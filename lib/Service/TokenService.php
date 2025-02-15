<?php

namespace OCA\SocialLogin\Service;

use DateTime;
use Hybridauth\Exception\HttpRequestFailedException;
use OC\User\LoginException;
use OCA\SocialLogin\Db\ConnectedLogin;
use OCA\SocialLogin\Db\ConnectedLoginMapper;
use OCA\SocialLogin\Db\Tokens;
use OCA\SocialLogin\Db\TokensMapper;
use OCA\SocialLogin\Service\Exceptions\NoTokensException;
use OCA\SocialLogin\Service\Exceptions\TokensException;
use OCA\SocialLogin\Task\RefreshTokensTask;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

/**
 * This is the central service of this fork, based in part on logics from upstream's ProviderService,
 * but also adding new logics.
 *
 * @author Manuel Biertz (mab@leibniz-psychology.org)
 * @author zorn-v
 */
class TokenService
{
    private TokensMapper $tokensMapper;
    private ConfigService $configService;
    private LoggerInterface $logger;
    private AdapterService $adapterService;
    private ConnectedLoginMapper $connectedLoginMapper;

    public function __construct(TokensMapper $tokensMapper, LoggerInterface $logger, ConfigService $configService, AdapterService $adapterService, ConnectedLoginMapper $connectedLoginMapper)
    {
        $this->tokensMapper = $tokensMapper;
        $this->logger = $logger;
        $this->configService = $configService;
        $this->adapterService = $adapterService;
        $this->connectedLoginMapper = $connectedLoginMapper;
    }

    public function authenticate($adapter, $providerType, $providerId){
        $adapter->authenticate();

        $profile = $adapter->getUserProfile(); // TODO whole paragraph: refactor to service / trait
        $profileId = preg_replace('#.*/#', '', rtrim($profile->identifier, '/'));
        $uid = $providerId.'-'.$profileId;
        if (strlen($uid) > 64 || !preg_match('#^[a-z0-9_.@-]+$#i', $profileId)) {
            $uid = $providerId.'-'.md5($profileId);
        }

        $accessTokens = $adapter->getAccessToken();
        $this->saveTokens($accessTokens, $uid, $providerType, $providerId);
    }

    /**
     * @return Tokens A user's sociallogin tokens for a single provider.
     * @throws TokensException
     * @throws NoTokensException
     */
    public function get(string $uid, string $providerId): Tokens {
        try {
            return $this->tokensMapper->find($uid, $providerId);
        } catch (DoesNotExistException $e) {
            // if not found, retry with connected login
            try {
                $identifiers = $this->connectedLoginMapper->getConnectedLogins($uid);

                // connected logins table does not include providerId
                // but identifier is always preceeded by providerId
                // so we search for the (first, but it should be only) one that is preceeded by providerId
                foreach ($identifiers as $identifier) {
                    // if (preg_match('/^'.preg_quote($providerId, '/').'.*/', $identifier)) {
                    if (preg_match('/^'.preg_quote($providerId, '/').'.*/', $identifier)) {
                        return $this->tokensMapper->find($identifier, $providerId);
                    }
                }
                throw new NoTokensException('Could not find tokens for uid '.$uid.'.');
            } catch (DoesNotExistException $e) {
                throw new NoTokensException('Could not find tokens for uid '.$uid.'.');
            }
        } catch (MultipleObjectsReturnedException $e) {
            throw new TokensException('There should be only one set of tokens per user, but we found multiple!');
        } catch (Exception $e) {
            throw new TokensException($e->getMessage());
        }
    }

    /**
     * Refreshes all pairs of tokens for all users that are due for renewal (= access token expired).
     *
     * Skips and removes orphaned token.
     *
     * @param bool $skipFailed Switch that enables skipping refreshes that have failed in the past. Defaults to false.
     *
     * @throws TokensException
     * @throws LoginException
     */
    public function refreshAllTokens(bool $skipFailed=false): void
    {
        try {
            $allTokens = $this->tokensMapper->findAll();
        } catch (Exception $e) {
            throw new TokensException($e->getMessage());
        }
        if (count($allTokens) === 0) {
            $this->logger->info("No tokens in database.");
            return;
        }
        foreach ($allTokens as $tokens) {
            if ($skipFailed && $tokens->getHasFailed()) {
                $this->logger->debug('Skipping tokens for {uid}, as they have failed in the past.', array('uid' => $tokens->getUid()));
                continue;
            }
            // Check whether tokens are orphaned and delete them, if so.
            if ($this->deleteOrphanedTokens($tokens)) {
                continue;
            }

            $this->refreshExpiredTokens($tokens);
        }
    }

    /**
     * Refresh a user's tokens for a single provider, if the access token is expired.
     *
     * @throws TokensException
     */
    public function refreshUserTokens(string $uid, string $providerId): void
    {
        try {
            $tokens = $this->get($uid, $providerId);
        } catch (NoTokensException $e) {
            return;
        }

        $this->refreshExpiredTokens($tokens);
    }

    /**
     * Refreshes a set of tokens after checking its orphanage and expiry status.
     */
    private function refreshExpiredTokens(Tokens $tokens): void
    {
        if ($tokens->isExpired()) {
            $this->refreshTokens($tokens);
        } else {
            $this->logger->info("Token for {uid} has not yet expired.", array('uid' => $tokens->getUid()));
        }
    }

    /**
     * Refreshes a set of tokens without checking for expiry status. If the refresh fails, failure is documented.
     *
     * @throws LoginException
     * @throws TokensException
     * @throws Exception
     */
    private function refreshTokens(Tokens $tokens): void
    {
            $config = $this->configService->customConfig($tokens->getProviderType(), $tokens->getProviderId());

            try {
                $adapter = $this->adapterService->new(ConfigService::TYPE_CLASSES[$tokens->getProviderType()],
                    $config, null);
            } catch (\Exception $e) {
                throw new TokensException($e->getMessage());
            }
            $this->logger->info("Trying to refresh token for {uid}.", array('uid' => $tokens->getUid()));
            $parameters = array(
                'client_id' => $config['keys']['id'],
                'client_secret' => $config['keys']['secret'],
                'grant_type' => 'refresh_token',
                'refresh_token' => $tokens->getRefreshToken(),
                'scope' => $config['scope']
            );
            try {
                $response = $adapter->refreshAccessToken($parameters);#
            } catch (HttpRequestFailedException $e) {
                $this->logger->info('Refreshing token for {uid} failed.', array('uid' => $tokens->getUid()));
                $tokens->setHasFailed(true);
                $this->tokensMapper->update($tokens);
                return;
            }
            $responseArr = json_decode($response, true);

            $this->logger->info("Saving refreshed token for {uid}.", array('uid' => $tokens->getUid()));

            $this->saveTokens($responseArr, $tokens->getUid(), $tokens->getProviderType(), $tokens->getProviderId());
    }


    /**
     * @throws TokensException
     */
    public function saveTokens(array $accessTokens, string $uid, string $providerType, string $providerId): void
    {
        // If response did not include "expires_at", calculate and add it here.
        if (!array_key_exists('expires_at', $accessTokens) && array_key_exists('expires_in', $accessTokens)) {
            $accessTokens['expires_at'] = time() + $accessTokens['expires_in'];
        }

        try {
            // $this->tokensMapper->insertOrUpdate($tokens) would fail, see https://github.com/nextcloud/server/issues/21705
            try {
                $tokens = $this->get($uid, $providerId);
                $tokens->setAccessToken($accessTokens['access_token']);
                $tokens->setRefreshToken($accessTokens['refresh_token']);
                $tokens->setExpiresAt(new DateTime('@' . $accessTokens['expires_at']));
                $tokens->setProviderType($providerType);
                $tokens->setProviderId($providerId);
                $tokens->setHasFailed(false);
                $this->tokensMapper->update($tokens);
            } catch (NoTokensException $e) {
                $tokens = new Tokens();
                $tokens->setUid($uid);
                $tokens->setAccessToken($accessTokens['access_token']);
                $tokens->setRefreshToken($accessTokens['refresh_token']);
                $tokens->setExpiresAt(new DateTime('@' . $accessTokens['expires_at']));
                $tokens->setProviderType($providerType);
                $tokens->setProviderId($providerId);
                $tokens->setHasFailed(false);
                $this->tokensMapper->insert($tokens);
            }
        } catch (Exception $e) {
            throw new TokensException($e->getMessage());
        }
    }

    /**
     * Checks whether a tokens are orphaned and deletes them, if so.
     *
     * @returns bool Whether the tokens had to be deleted.
     * @throws TokensException
     * @throws LoginException
     */
    private function deleteOrphanedTokens(Tokens $tokens): bool
    {
        if ($tokens->isOrphaned($this->configService)) {
            try {
                $this->tokensMapper->delete($tokens); // Delete keys from formerly active identity providers.
                $this->logger->warning("Deleted orphaned {provider} key of {uid}.", array(
                    'uid' => $tokens->getUid(),
                    'provider' => $tokens->getProviderId()
                ));
            } catch (Exception $e) {
                throw new TokensException($e->getMessage());
            }
            return true;
        }
        return false;
    }
}

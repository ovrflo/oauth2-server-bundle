<?php

declare(strict_types=1);

namespace League\Bundle\OAuth2ServerBundle\Security\Authenticator;

use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use League\Bundle\OAuth2ServerBundle\Security\Exception\OAuth2AuthenticationException;
use League\Bundle\OAuth2ServerBundle\Security\Exception\OAuth2AuthenticationFailedException;
use League\Bundle\OAuth2ServerBundle\Security\Passport\Badge\OAuth2Badge;
use League\Bundle\OAuth2ServerBundle\Security\User\NullUser;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Passport\UserPassportInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * @author Mathias Arlaud <mathias.arlaud@gmail.com>
 */
final class OAuth2Authenticator implements AuthenticatorInterface, AuthenticationEntryPointInterface
{
    /**
     * @var HttpMessageFactoryInterface
     */
    private $httpMessageFactory;

    /**
     * @var ResourceServer
     */
    private $resourceServer;

    /**
     * @var UserProviderInterface
     */
    private $userProvider;

    /**
     * @var string
     */
    private $rolePrefix;

    public function __construct(
        HttpMessageFactoryInterface $httpMessageFactory,
        ResourceServer $resourceServer,
        UserProviderInterface $userProvider,
        string $rolePrefix
    ) {
        $this->httpMessageFactory = $httpMessageFactory;
        $this->resourceServer = $resourceServer;
        $this->userProvider = $userProvider;
        $this->rolePrefix = $rolePrefix;
    }

    public function supports(Request $request): ?bool
    {
        return null;
    }

    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        $exception = new UnauthorizedHttpException('Bearer');

        return new Response('', $exception->getStatusCode(), $exception->getHeaders());
    }

    public function authenticate(Request $request): PassportInterface
    {
        try {
            $psr7Request = $this->resourceServer->validateAuthenticatedRequest($this->httpMessageFactory->createRequest($request));
        } catch (OAuthServerException $e) {
            throw OAuth2AuthenticationFailedException::create('The resource server rejected the request.', $e);
        }

        /** @var string $userIdentifier */
        $userIdentifier = $psr7Request->getAttribute('oauth_user_id', '');

        /** @var string $accessTokenId */
        $accessTokenId = $psr7Request->getAttribute('oauth_access_token_id');

        /** @var list<string> $scopes */
        $scopes = $psr7Request->getAttribute('oauth_scopes', []);

        $passport = new SelfValidatingPassport($this->getUserBadge($userIdentifier));

        // BC Layer for 5.1 version
        if (!method_exists($passport, 'setAttribute')) {
            $passport->addBadge(new OAuth2Badge($accessTokenId, $scopes));
        } else {
            $passport->setAttribute('accessTokenId', $accessTokenId);
            $passport->setAttribute('scopes', $scopes);
        }

        return $passport;
    }

    /**
     * @return OAuth2Token
     */
    public function createAuthenticatedToken(PassportInterface $passport, string $firewallName): TokenInterface
    {
        if (!$passport instanceof UserPassportInterface) {
            throw new \RuntimeException(sprintf('Cannot create a OAuth2 authenticated token. $passport should be a %s', UserPassportInterface::class));
        }

        // BC Layer for 5.1 version
        /** @var Passport $passport */
        if (!method_exists($passport, 'getAttribute')) {
            /** @var OAuth2Badge $oauth2Badge */
            $oauth2Badge = $passport->getBadge(OAuth2Badge::class);

            $accessTokenId = $oauth2Badge->getAccessTokenId();
            $scopes = $oauth2Badge->getScopes();
        } else {
            /** @var string $accessTokenId */
            $accessTokenId = $passport->getAttribute('accessTokenId');

            /** @var list<string> $scopes */
            $scopes = $passport->getAttribute('scopes');
        }

        $token = new OAuth2Token($passport->getUser(), $accessTokenId, $scopes, $this->rolePrefix);
        $token->setAuthenticated(true);

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // $exception is already customized.
        if ($exception instanceof OAuth2AuthenticationException) {
            throw $exception;
        }

        throw new UnauthorizedHttpException('Bearer', $exception->getMessage(), $exception);
    }

    /**
     * @return UserBadge|UserInterface
     */
    private function getUserBadge(string $userIdentifier)
    {
        $getUserCallable = function (string $userIdentifier): UserInterface {
            return '' !== $userIdentifier ? $this->userProvider->loadUserByUsername($userIdentifier) : new NullUser();
        };

        // BC Layer for 5.1 version
        if (!class_exists(UserBadge::class)) {
            return $getUserCallable($userIdentifier);
        }

        return new UserBadge($userIdentifier, $getUserCallable);
    }
}

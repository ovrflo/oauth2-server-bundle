<?php

declare(strict_types=1);

namespace League\Bundle\OAuth2ServerBundle\DependencyInjection\Security;

use League\Bundle\OAuth2ServerBundle\Security\Authentication\Provider\OAuth2Provider;
use League\Bundle\OAuth2ServerBundle\Security\Authenticator\OAuth2Authenticator;
use League\Bundle\OAuth2ServerBundle\Security\EntryPoint\OAuth2EntryPoint;
use League\Bundle\OAuth2ServerBundle\Security\Firewall\OAuth2Listener;
use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory\AuthenticatorFactoryInterface;
use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory\SecurityFactoryInterface;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @author Mathias Arlaud <mathias.arlaud@gmail.com>
 */
final class OAuth2Factory implements SecurityFactoryInterface, AuthenticatorFactoryInterface
{
    public function create(ContainerBuilder $container, $id, $config, $userProvider, $defaultEntryPoint): array
    {
        $provider = sprintf('security.authentication.provider.oauth2.%s', $id);
        $container
            ->setDefinition($provider, new ChildDefinition(OAuth2Provider::class))
            ->replaceArgument(0, new Reference($userProvider))
            ->replaceArgument(3, $id);

        $listener = sprintf('security.authentication.listener.oauth2.%s', $id);
        $container
            ->setDefinition($listener, new ChildDefinition(OAuth2Listener::class))
            ->replaceArgument(4, $id);

        return [$provider, $listener, OAuth2EntryPoint::class];
    }

    public function createAuthenticator(ContainerBuilder $container, string $firewallName, array $config, string $userProvider): string
    {
        $authenticator = sprintf('security.authenticator.oauth2.%s', $firewallName);
        $container->setDefinition($authenticator, new ChildDefinition(OAuth2Authenticator::class));

        return $authenticator;
    }

    public function getPosition(): string
    {
        return 'pre_auth';
    }

    public function getKey(): string
    {
        return 'oauth2';
    }

    public function addConfiguration(NodeDefinition $builder): void
    {
    }
}

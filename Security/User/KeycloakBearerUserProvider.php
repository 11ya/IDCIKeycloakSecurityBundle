<?php

namespace NTI\KeycloakSecurityBundle\Security\User;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use NTI\KeycloakSecurityBundle\Provider\Keycloak;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2Client;
use KnpU\OAuth2ClientBundle\Security\User\OAuthUserProvider;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class KeycloakBearerUserProvider extends OAuthUserProvider
{
    /**
     * @var ClientRegistry
     */
    protected $clientRegistry;

    /**
     * @var mixed
     */
    protected $sslVerification;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /** @var EntityManagerInterface */
    protected $em;

    public function __construct(ClientRegistry $clientRegistry, $sslVerification, ContainerInterface $container)
    {
        $this->clientRegistry = $clientRegistry;
        $this->sslVerification = $sslVerification;
        $this->container = $container;
        $this->em = $this->container->get('doctrine')->getManager();
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByUsername($accessToken): UserInterface
    {
        $provider = $this->getKeycloakClient()->getOAuth2Provider();

        if (!$provider instanceof Keycloak) {
            throw new \RuntimeException(
                sprintf('The OAuth2 client provider must be an instance of %s', Keycloak::class)
            );
        }

        $response = (new Client())->request('POST', $provider->getTokenIntrospectionUrl(), [
            'auth' => [$provider->getClientId(), $provider->getClientSecret()],
            'form_params' => [
                'token' => $accessToken,
            ],
            'verify' => $this->sslVerification,
        ]);

        $jwt = json_decode($response->getBody(), true);

        if (!$jwt['active']) {
            throw new \UnexpectedValueException('The token does not exist or is not valid anymore');
        }

        if(isset($jwt['resource_access'])) {
            if (!isset($jwt['resource_access'][$provider->getClientId()])) {
                throw new \UnexpectedValueException(
                    sprintf(
                        'The token does not have the necessary permissions. Current ressource access : %s',
                        json_encode($jwt['resource_access'])
                    )
                );
            }
        }

        // Roles
        $roles = [];
        if(isset($jwt['resource_access'])) {
            $roles = $jwt['resource_access'][$provider->getClientId()]['roles'];
            $rolesTmp = array(); // Remove denied roles
            foreach($jwt['resource_access'][$provider->getClientId()]['roles'] as $val) $rolesTmp[$val] = 1;
            if(isset($jwt['denied_roles']) && isset($jwt['denied_roles'][$provider->getClientId()])){
                $deniedRoles = $jwt['denied_roles'][$provider->getClientId()] ?? "[]";
                if(!is_array($deniedRoles ?? "[]")) $deniedRoles = json_decode($deniedRoles ?? "[]",true);
                foreach($deniedRoles as $val) unset($rolesTmp[$val]);
            }
            $roles = array_keys($rolesTmp);
        }
        
        // Get local user
        $localUser = null;
        if($this->em && $repo = $this->container->getParameter("user_class",null)){
            $localUser = $this->em->getRepository($repo)->findOneBy(array("email" => $jwt['email'])) ?? null;
        }

        return new KeycloakBearerUser(
            $jwt['email'],
            $roles,
            $localUser,
            new AccessToken(array('access_token' => $accessToken)),
            $jwt['sub'],
            $jwt['email'],
            $jwt['given_name'],
            $jwt['family_name'],
            $jwt['client_id']
        );
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof KeycloakBearerUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        $user = $this->loadUserByUsername($user->getAccessToken());

        if (!$user) {
            throw new UsernameNotFoundException();
        }

        return $user;
    }

    public function supportsClass($class): bool
    {
        return KeycloakBearerUser::class === $class;
    }

    protected function getKeycloakClient(): OAuth2Client
    {
        return $this->clientRegistry->getClient('keycloak');
    }
}

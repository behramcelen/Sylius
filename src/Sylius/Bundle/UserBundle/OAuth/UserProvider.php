<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sylius\Bundle\UserBundle\OAuth;

use Doctrine\Common\Persistence\ObjectManager;
use HWI\Bundle\OAuthBundle\Connect\AccountConnectorInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;
use Sylius\Bundle\UserBundle\Provider\UsernameOrEmailProvider as BaseUserProvider;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\Component\User\Canonicalizer\CanonicalizerInterface;
use Sylius\Component\User\Model\CustomerInterface;
use Sylius\Component\User\Model\UserInterface as SyliusUserInterface;
use Sylius\Component\User\Model\UserOAuthInterface;
use Sylius\Component\User\Repository\UserRepositoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Loading and ad-hoc creation of a user by an OAuth sign-in provider account.
 *
 * @author Fabian Kiss <fabian.kiss@ymc.ch>
 * @author Joseph Bielawski <stloyd@gmail.com>
 * @author Łukasz Chruściel <lukasz.chrusciel@lakion.com>
 */
class UserProvider extends BaseUserProvider implements AccountConnectorInterface, OAuthAwareUserProviderInterface
{
    /**
     * @var FactoryInterface
     */
    protected $oauthFactory;

    /**
     * @var RepositoryInterface
     */
    protected $oauthRepository;

    /**
     * @var FactoryInterface
     */
    protected $customerFactory;

    /**
     * @var RepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var FactoryInterface
     */
    protected $userFactory;

    /**
     * @var ObjectManager
     */
    protected $userManager;

    /**
     * @param FactoryInterface $customerFactory
     * @param RepositoryInterface $customerRepository
     * @param FactoryInterface $userFactory
     * @param UserRepositoryInterface $userRepository
     * @param FactoryInterface $oauthFactory
     * @param RepositoryInterface $oauthRepository
     * @param ObjectManager $userManager
     * @param CanonicalizerInterface $canonicalizer
     */
    public function __construct(
        FactoryInterface $customerFactory,
        RepositoryInterface $customerRepository,
        FactoryInterface $userFactory,
        UserRepositoryInterface $userRepository,
        FactoryInterface $oauthFactory,
        RepositoryInterface $oauthRepository,
        ObjectManager $userManager,
        CanonicalizerInterface $canonicalizer
    ) {
        parent::__construct($userRepository, $canonicalizer);

        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->oauthFactory = $oauthFactory;
        $this->oauthRepository = $oauthRepository;
        $this->userFactory = $userFactory;
        $this->userManager = $userManager;
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByOAuthUserResponse(UserResponseInterface $response)
    {
        $oauth = $this->oauthRepository->findOneBy([
            'provider' => $response->getResourceOwner()->getName(),
            'identifier' => $response->getUsername(),
        ]);

        if ($oauth instanceof UserOAuthInterface) {
            return $oauth->getUser();
        }

        if (null !== $response->getEmail()) {
            $user = $this->userRepository->findOneByEmail($response->getEmail());
            if (null !== $user) {
                return $this->updateUserByOAuthUserResponse($user, $response);
            }
        }

        return $this->createUserByOAuthUserResponse($response);
    }

    /**
     * {@inheritdoc}
     */
    public function connect(UserInterface $user, UserResponseInterface $response)
    {
        /* @var $user SyliusUserInterface */
        $this->updateUserByOAuthUserResponse($user, $response);
    }

    /**
     * Ad-hoc creation of user.
     *
     * @param UserResponseInterface $response
     *
     * @return SyliusUserInterface
     */
    protected function createUserByOAuthUserResponse(UserResponseInterface $response)
    {
        /** @var \Sylius\Component\User\Model\UserInterface $user */
        $user = $this->userFactory->createNew();
        /** @var CustomerInterface $customer */
        $customer = $this->customerFactory->createNew();
        $user->setCustomer($customer);

        // set default values taken from OAuth sign-in provider account
        if (null !== $email = $response->getEmail()) {
            $customer->setEmail($email);
        }

        if (null !== $realName = $response->getRealName()) {
            $customer->setFirstName($realName);
        }

        if (!$user->getUsername()) {
            $user->setUsername($response->getEmail() ?: $response->getNickname());
        }

        // set random password to prevent issue with not nullable field & potential security hole
        $user->setPlainPassword(substr(sha1($response->getAccessToken()), 0, 10));

        $user->setEnabled(true);

        return $this->updateUserByOAuthUserResponse($user, $response);
    }

    /**
     * Attach OAuth sign-in provider account to existing user.
     *
     * @param UserInterface         $user
     * @param UserResponseInterface $response
     *
     * @return UserInterface
     */
    protected function updateUserByOAuthUserResponse(UserInterface $user, UserResponseInterface $response)
    {
        $oauth = $this->oauthFactory->createNew();
        $oauth->setIdentifier($response->getUsername());
        $oauth->setProvider($response->getResourceOwner()->getName());
        $oauth->setAccessToken($response->getAccessToken());
        $oauth->setRefreshToken($response->getRefreshToken());

        /* @var $user SyliusUserInterface */
        $user->addOAuthAccount($oauth);

        $this->userManager->persist($user);
        $this->userManager->flush();

        return $user;
    }
}

<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Tests\LoginLink;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\Signature\ExpiredSignatureStorage;
use Symfony\Component\Security\Core\Signature\SignatureHasher;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\LoginLink\Exception\ExpiredLoginLinkException;
use Symfony\Component\Security\Http\LoginLink\Exception\InvalidLoginLinkException;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandler;

class LoginLinkHandlerTest extends TestCase
{
    /** @var MockObject|UrlGeneratorInterface */
    private $router;
    /** @var TestLoginLinkHandlerUserProvider */
    private $userProvider;
    /** @var PropertyAccessorInterface */
    private $propertyAccessor;
    /** @var MockObject|ExpiredSignatureStorage */
    private $expiredLinkStorage;
    /** @var CacheItemPoolInterface */
    private $expiredLinkCache;

    protected function setUp(): void
    {
        $this->router = $this->createMock(UrlGeneratorInterface::class);
        $this->userProvider = new TestLoginLinkHandlerUserProvider();
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
        $this->expiredLinkCache = new ArrayAdapter();
        $this->expiredLinkStorage = new ExpiredSignatureStorage($this->expiredLinkCache, 360);
    }

    /**
     * @dataProvider provideCreateLoginLinkData
     */
    public function testCreateLoginLink($user, array $extraProperties, Request $request = null)
    {
        $this->router->expects($this->once())
            ->method('generate')
            ->with(
                'app_check_login_link_route',
                $this->callback(function ($parameters) use ($extraProperties) {
                    return 'weaverryan' == $parameters['user']
                        && isset($parameters['expires'])
                        && isset($parameters['hash'])
                         // allow a small expiration offset to avoid time-sensitivity
                        && abs(time() + 600 - $parameters['expires']) <= 1
                        // make sure hash is what we expect
                        && $parameters['hash'] === $this->createSignatureHash('weaverryan', $parameters['expires'], array_values($extraProperties));
                }),
                UrlGeneratorInterface::ABSOLUTE_URL
            )
            ->willReturn('https://example.com/login/verify?user=weaverryan&hash=abchash&expires=1601235000');

        if ($request) {
            $this->router->expects($this->once())
                ->method('getContext')
                ->willReturn($currentRequestContext = new RequestContext());

            $this->router->expects($this->exactly(2))
                ->method('setContext')
                ->withConsecutive([$this->equalTo((new RequestContext())->fromRequest($request)->setParameter('_locale', $request->getLocale()))], [$currentRequestContext]);
        }

        $loginLink = $this->createLinker([], array_keys($extraProperties))->createLoginLink($user, $request);
        $this->assertSame('https://example.com/login/verify?user=weaverryan&hash=abchash&expires=1601235000', $loginLink->getUrl());
    }

    public function provideCreateLoginLinkData()
    {
        yield [
            new TestLoginLinkHandlerUser('weaverryan', 'ryan@symfonycasts.com', 'pwhash'),
            ['emailProperty' => 'ryan@symfonycasts.com', 'passwordProperty' => 'pwhash'],
            Request::create('https://example.com'),
        ];

        yield [
            new TestLoginLinkHandlerUser('weaverryan', 'ryan@symfonycasts.com', 'pwhash'),
            ['emailProperty' => 'ryan@symfonycasts.com', 'passwordProperty' => 'pwhash'],
        ];

        yield [
            new TestLoginLinkHandlerUser('weaverryan', 'ryan@symfonycasts.com', 'pwhash'),
            ['lastAuthenticatedAt' => ''],
        ];

        yield [
            new TestLoginLinkHandlerUser('weaverryan', 'ryan@symfonycasts.com', 'pwhash', new \DateTime('2020-06-01 00:00:00', new \DateTimeZone('+0000'))),
            ['lastAuthenticatedAt' => '2020-06-01T00:00:00+00:00'],
        ];
    }

    public function testConsumeLoginLink()
    {
        $expires = time() + 500;
        $signature = $this->createSignatureHash('weaverryan', $expires, ['ryan@symfonycasts.com', 'pwhash']);
        $request = Request::create(sprintf('/login/verify?user=weaverryan&hash=%s&expires=%d', $signature, $expires));

        $user = new TestLoginLinkHandlerUser('weaverryan', 'ryan@symfonycasts.com', 'pwhash');
        $this->userProvider->createUser($user);

        $linker = $this->createLinker(['max_uses' => 3]);
        $actualUser = $linker->consumeLoginLink($request);
        $this->assertEquals($user, $actualUser);

        $item = $this->expiredLinkCache->getItem(rawurlencode($signature));
        $this->assertSame(1, $item->get());
    }

    public function testConsumeLoginLinkWithExpired()
    {
        $this->expectException(ExpiredLoginLinkException::class);
        $expires = time() - 500;
        $signature = $this->createSignatureHash('weaverryan', $expires, ['ryan@symfonycasts.com', 'pwhash']);
        $request = Request::create(sprintf('/login/verify?user=weaverryan&hash=%s&expires=%d', $signature, $expires));

        $user = new TestLoginLinkHandlerUser('weaverryan', 'ryan@symfonycasts.com', 'pwhash');
        $this->userProvider->createUser($user);

        $linker = $this->createLinker(['max_uses' => 3]);
        $linker->consumeLoginLink($request);
    }

    public function testConsumeLoginLinkWithUserNotFound()
    {
        $this->expectException(InvalidLoginLinkException::class);
        $request = Request::create('/login/verify?user=weaverryan&hash=thehash&expires=10000');

        $linker = $this->createLinker();
        $linker->consumeLoginLink($request);
    }

    public function testConsumeLoginLinkWithDifferentSignature()
    {
        $this->expectException(InvalidLoginLinkException::class);
        $request = Request::create(sprintf('/login/verify?user=weaverryan&hash=fake_hash&expires=%d', time() + 500));

        $user = new TestLoginLinkHandlerUser('weaverryan', 'ryan@symfonycasts.com', 'pwhash');
        $this->userProvider->createUser($user);

        $linker = $this->createLinker();
        $linker->consumeLoginLink($request);
    }

    public function testConsumeLoginLinkExceedsMaxUsage()
    {
        $this->expectException(ExpiredLoginLinkException::class);
        $expires = time() + 500;
        $signature = $this->createSignatureHash('weaverryan', $expires, ['ryan@symfonycasts.com', 'pwhash']);
        $request = Request::create(sprintf('/login/verify?user=weaverryan&hash=%s&expires=%d', $signature, $expires));

        $user = new TestLoginLinkHandlerUser('weaverryan', 'ryan@symfonycasts.com', 'pwhash');
        $this->userProvider->createUser($user);

        $item = $this->expiredLinkCache->getItem(rawurlencode($signature));
        $item->set(3);
        $this->expiredLinkCache->save($item);

        $linker = $this->createLinker(['max_uses' => 3]);
        $linker->consumeLoginLink($request);
    }

    private function createSignatureHash(string $username, int $expires, array $extraFields): string
    {
        $fields = [base64_encode($username), $expires];
        foreach ($extraFields as $extraField) {
            $fields[] = base64_encode($extraField);
        }

        // matches hash logic in the class
        return base64_encode(hash_hmac('sha256', implode(':', $fields), 's3cret'));
    }

    private function createLinker(array $options = [], array $extraProperties = ['emailProperty', 'passwordProperty']): LoginLinkHandler
    {
        $options = array_merge([
            'lifetime' => 600,
            'route_name' => 'app_check_login_link_route',
        ], $options);

        return new LoginLinkHandler($this->router, $this->userProvider, new SignatureHasher($this->propertyAccessor, $extraProperties, 's3cret', $this->expiredLinkStorage, $options['max_uses'] ?? null), $options);
    }
}

class TestLoginLinkHandlerUserProvider implements UserProviderInterface
{
    private $users = [];

    public function createUser(TestLoginLinkHandlerUser $user): void
    {
        $this->users[$user->getUserIdentifier()] = $user;
    }

    public function loadUserByUsername($username): TestLoginLinkHandlerUser
    {
        return $this->loadUserByIdentifier($username);
    }

    public function loadUserByIdentifier(string $userIdentifier): TestLoginLinkHandlerUser
    {
        if (!isset($this->users[$userIdentifier])) {
            throw new UserNotFoundException();
        }

        return clone $this->users[$userIdentifier];
    }

    public function refreshUser(UserInterface $user)
    {
        return $this->users[$username];
    }

    public function supportsClass(string $class)
    {
        return TestLoginLinkHandlerUser::class === $class;
    }
}

class TestLoginLinkHandlerUser implements UserInterface
{
    public $username;
    public $emailProperty;
    public $passwordProperty;
    public $lastAuthenticatedAt;

    public function __construct($username, $emailProperty, $passwordProperty, $lastAuthenticatedAt = null)
    {
        $this->username = $username;
        $this->emailProperty = $emailProperty;
        $this->passwordProperty = $passwordProperty;
        $this->lastAuthenticatedAt = $lastAuthenticatedAt;
    }

    public function getRoles()
    {
        return [];
    }

    public function getPassword()
    {
        return $this->passwordProperty;
    }

    public function getSalt()
    {
        return '';
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getUserIdentifier()
    {
        return $this->username;
    }

    public function eraseCredentials()
    {
    }
}
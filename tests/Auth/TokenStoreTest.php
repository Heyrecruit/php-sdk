<?php
declare(strict_types=1);

namespace Heyrecruit\Test\Auth;

use Heyrecruit\Auth\AccessToken;
use Heyrecruit\Auth\ArrayTokenStore;
use Heyrecruit\Auth\SessionTokenStore;
use PHPUnit\Framework\TestCase;

final class TokenStoreTest extends TestCase {

	protected function tearDown(): void {
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}

		$_SESSION = [];
		parent::tearDown();
	}

	public function testArrayStoreKeepsAValidToken(): void {
		$store = new ArrayTokenStore();
		$store->set(new AccessToken('jwt', time() + 60));

		$this->assertSame('jwt', $store->get()?->token);
	}

	public function testArrayStoreHidesAnExpiredToken(): void {
		$store = new ArrayTokenStore();
		$store->set(new AccessToken('jwt', time() - 1));

		$this->assertNull($store->get());
	}

	public function testArrayStoreForgetsOnClear(): void {
		$store = new ArrayTokenStore();
		$store->set(new AccessToken('jwt', time() + 60));
		$store->clear();

		$this->assertNull($store->get());
	}

	public function testSessionStoreIsInertWithoutASession(): void {
		$this->assertSame(PHP_SESSION_NONE, session_status(), 'Der Test setzt voraus, dass keine Session laeuft.');

		$store = new SessionTokenStore();
		$store->set(new AccessToken('jwt', time() + 60));

		$this->assertNull($store->get(), 'Ohne Session darf kein Token vorgetaeuscht werden.');
		$this->assertArrayNotHasKey(SessionTokenStore::DEFAULT_SESSION_KEY, $_SESSION);
	}

	public function testSessionStoreRoundTrip(): void {
		session_start();

		$store = new SessionTokenStore();
		$store->set(new AccessToken('jwt', time() + 60));

		$this->assertSame('jwt', $store->get()?->token);
		$this->assertSame('jwt', $_SESSION[SessionTokenStore::DEFAULT_SESSION_KEY]['token']);
	}

	public function testSessionStoreClearRemovesTheKey(): void {
		session_start();

		$store = new SessionTokenStore();
		$store->set(new AccessToken('jwt', time() + 60));
		$store->clear();

		$this->assertNull($store->get());
		$this->assertArrayNotHasKey(SessionTokenStore::DEFAULT_SESSION_KEY, $_SESSION);
	}

	public function testSessionStoreIgnoresGarbage(): void {
		session_start();
		$_SESSION[SessionTokenStore::DEFAULT_SESSION_KEY] = 'not-an-array';

		$this->assertNull((new SessionTokenStore())->get());
	}

	public function testSessionStoreIgnoresAnExpiredStoredToken(): void {
		session_start();
		$_SESSION[SessionTokenStore::DEFAULT_SESSION_KEY] = ['token' => 'stale', 'expiration' => time() - 5];

		$this->assertNull((new SessionTokenStore())->get());
	}

	public function testSessionStoreUsesTheConfiguredKey(): void {
		session_start();

		$store = new SessionTokenStore('OWN_KEY');
		$store->set(new AccessToken('jwt', time() + 60));

		$this->assertArrayHasKey('OWN_KEY', $_SESSION);
		$this->assertArrayNotHasKey(SessionTokenStore::DEFAULT_SESSION_KEY, $_SESSION);
	}
}

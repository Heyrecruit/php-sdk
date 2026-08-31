<?php
declare(strict_types=1);

namespace Heyrecruit\Test;

use Heyrecruit\Auth\SessionTokenStore;
use Heyrecruit\HeyRestApi;
use Heyrecruit\Test\Fake\FakeTransport;
use PHPUnit\Framework\TestCase;

final class SessionIsolationTest extends TestCase {

	protected function tearDown(): void {
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}

		$_SESSION = [];
		parent::tearDown();
	}

	private function config(int $clientId, string $secret): array {
		return [
			'SCOPE_URL'           => 'https://example.test/api/v2',
			'SCOPE_CLIENT_ID'     => $clientId,
			'SCOPE_CLIENT_SECRET' => $secret,
		];
	}

	public function testTwoInstallationsInOneSessionDoNotShareAToken(): void {
		session_start();

		$first = new FakeTransport();
		$first->queueAuth(FakeTransport::authSuccess('jwt-company-111'));
		new HeyRestApi($this->config(111, 'SCOPE!one'), $first);

		$second = new FakeTransport();
		$second->queueAuth(FakeTransport::authSuccess('jwt-company-222'));
		$api = new HeyRestApi($this->config(222, 'SCOPE!two'), $second);
		$api->getCompanyDetail(222);

		$this->assertCount(1, $second->callsTo('auth'), 'Die zweite Firma muss einen eigenen Token holen.');
		$this->assertContains(
			'Authorization: Bearer jwt-company-222',
			$second->callsTo('companies/view')[0]['headers'],
			'Die zweite Firma darf nicht mit dem Token der ersten senden.'
		);
	}

	public function testTheSameInstallationReusesItsTokenAcrossInstances(): void {
		session_start();

		$first = new FakeTransport();
		$first->queueAuth(FakeTransport::authSuccess('jwt-shared'));
		new HeyRestApi($this->config(111, 'SCOPE!one'), $first);

		$second = new FakeTransport();
		$api    = new HeyRestApi($this->config(111, 'SCOPE!one'), $second);
		$api->getCompanyDetail(111);

		$this->assertCount(0, $second->callsTo('auth'), 'Der Session-Cache muss weiter greifen.');
		$this->assertContains('Authorization: Bearer jwt-shared', $second->callsTo('companies/view')[0]['headers']);
	}

	public function testTheSameClientIdWithADifferentSecretGetsItsOwnToken(): void {
		session_start();

		$first = new FakeTransport();
		$first->queueAuth(FakeTransport::authSuccess('jwt-old-secret'));
		new HeyRestApi($this->config(111, 'SCOPE!old'), $first);

		$second = new FakeTransport();
		$second->queueAuth(FakeTransport::authSuccess('jwt-new-secret'));
		$api = new HeyRestApi($this->config(111, 'SCOPE!new'), $second);
		$api->getCompanyDetail(111);

		$this->assertCount(1, $second->callsTo('auth'), 'Ein anderes Secret muss einen eigenen Token holen.');
		$this->assertContains('Authorization: Bearer jwt-new-secret', $second->callsTo('companies/view')[0]['headers']);
	}

	public function testTheSessionKeyCarriesAFingerprintNotTheSecret(): void {
		session_start();

		$transport = new FakeTransport();
		new HeyRestApi($this->config(111, 'SCOPE!super-secret'), $transport);

		$keys = array_keys($_SESSION);
		$this->assertCount(1, $keys);
		$this->assertStringStartsWith(SessionTokenStore::DEFAULT_SESSION_KEY . '_', $keys[0]);
		$this->assertStringNotContainsString('super-secret', $keys[0], 'Das Secret darf nicht im Schluessel stehen.');
	}
}

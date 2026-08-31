<?php
declare(strict_types=1);

namespace Heyrecruit\Test;

use Heyrecruit\Auth\ArrayTokenStore;
use Heyrecruit\HeyRestApi;
use Heyrecruit\Http\ApiResponse;
use Heyrecruit\Test\Fake\FakeTransport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HeyRestApiTest extends TestCase {

	private const CONFIG = [
		'SCOPE_URL'           => 'https://example.test/api/v2',
		'SCOPE_CLIENT_ID'     => 42,
		'SCOPE_CLIENT_SECRET' => 'SCOPE!secret',
	];

	protected function tearDown(): void {
		unset($_SERVER['REMOTE_ADDR']);
		parent::tearDown();
	}

	private function api(FakeTransport $transport): HeyRestApi {
		return new HeyRestApi(self::CONFIG, $transport, new ArrayTokenStore());
	}

	public function testEmptyConfigIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('No configuration settings submitted.');

		new HeyRestApi([], new FakeTransport(), new ArrayTokenStore());
	}

	public function testMissingUrlIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing SCOPE_URL parameter.');

		new HeyRestApi(['SCOPE_CLIENT_ID' => 1, 'SCOPE_CLIENT_SECRET' => 'x'], new FakeTransport(), new ArrayTokenStore());
	}

	public function testMissingClientIdIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing CLIENT_ID parameter.');

		new HeyRestApi(['SCOPE_URL' => 'https://example.test', 'SCOPE_CLIENT_SECRET' => 'x'], new FakeTransport(), new ArrayTokenStore());
	}

	public function testTrailingSlashIsStrippedFromTheBaseUrl(): void {
		$api = new HeyRestApi(
			['SCOPE_URL' => 'https://example.test/api/v2/'] + self::CONFIG,
			new FakeTransport(),
			new ArrayTokenStore()
		);

		$this->assertSame('https://example.test/api/v2', $api->scope_url);
	}

	public function testConstructorAuthenticatesOnce(): void {
		$transport = new FakeTransport();
		$this->api($transport);

		$this->assertCount(1, $transport->callsTo('auth'));
	}

	public function testBearerTokenIsSentOnEveryRequest(): void {
		$transport = new FakeTransport();
		$transport->queueAuth(FakeTransport::authSuccess('jwt-abc'));

		$this->api($transport)->getCompanyDetail(7);

		$call = $transport->callsTo('companies/view')[0];
		$this->assertContains('Authorization: Bearer jwt-abc', $call['headers']);
		$this->assertContains('Content-Type: application/json; charset=UTF-8', $call['headers']);
	}

	public function testGetRequestsCarryIpAndLanguage(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';

		$transport = new FakeTransport();
		$api       = $this->api($transport);
		$api->setFilter('language=de');
		$api->getJobs(5);

		$payload = $transport->callsTo('jobs/index')[0]['payload'];
		$this->assertSame('2001:db8::1', $payload['ip']);
		$this->assertSame('de', $payload['language']);
	}

	public function testSetFilterAccumulatesSoAnEarlierLanguageSurvives(): void {
		// Der Konsument setzt zuerst den Query-String der Anfrage und danach einen engeren Filter
		// (scope-rest-templates: HeyUtility::__construct und ::getJobs). Die Sprache aus der URL
		// darf dabei nicht verloren gehen.
		$transport = new FakeTransport();
		$api       = $this->api($transport);

		$api->setFilter('language=en&search=Pflege');
		$api->setFilter('employments%5B0%5D=7&employments%5B1%5D=6');
		$api->getJobs(1);

		$payload = $transport->callsTo('jobs/index')[0]['payload'];
		$this->assertSame('en', $payload['language'], 'Die Sprache aus dem ersten Aufruf muss erhalten bleiben.');
		$this->assertSame('Pflege', $payload['search']);
		$this->assertSame(['7', '6'], $payload['employments']);
	}

	public function testReplaceFilterStartsFromTheDefaults(): void {
		$transport = new FakeTransport();
		$api       = $this->api($transport);

		$api->setFilter('language=en&search=Pflege');
		$api->replaceFilter('employments%5B0%5D=7');
		$api->getJobs(1);

		$payload = $transport->callsTo('jobs/index')[0]['payload'];
		$this->assertNull($payload['language'], 'replaceFilter muss alles Vorherige verwerfen.');
		$this->assertNull($payload['search']);
		$this->assertSame(['7'], $payload['employments']);
	}

	public function testMissingRemoteAddrDoesNotBreakTheRequest(): void {
		unset($_SERVER['REMOTE_ADDR']);

		$transport = new FakeTransport();
		$this->api($transport)->getJobs(5);

		$this->assertSame('', $transport->callsTo('jobs/index')[0]['payload']['ip']);
	}

	public function testExpiredTokenTriggersOneForcedRenewalAndARetry(): void {
		$transport = new FakeTransport();
		$transport->queueAuth(FakeTransport::authSuccess('old'), FakeTransport::authSuccess('new'));
		$transport->queue(
			FakeTransport::expiredToken(),
			new ApiResponse(200, ['status' => 'success', 'data' => ['jobs' => []]])
		);

		$result = $this->api($transport)->getJobs(1);

		$this->assertSame(200, $result['status_code']);
		$this->assertCount(2, $transport->callsTo('jobs/index'), 'Der Request muss genau einmal wiederholt werden.');
		$this->assertCount(2, $transport->callsTo('auth'), 'Nach dem 401 muss neu authentifiziert werden.');
		$this->assertContains('Authorization: Bearer new', $transport->callsTo('jobs/index')[1]['headers']);
	}

	public function testAFailedRenewalLeavesNoStaleTokenBehind(): void {
		$store     = new ArrayTokenStore();
		$transport = new FakeTransport();
		$transport->queueAuth(
			FakeTransport::authSuccess('old'),
			new ApiResponse(400, ['status' => 'error', 'errors' => 'Authentication failed. Wrong client_id or client_secret.'])
		);
		$transport->queue(FakeTransport::expiredToken());

		$api = new HeyRestApi(self::CONFIG, $transport, $store);
		$this->assertSame('old', $store->get()?->token);

		try {
			$api->getJobs(1);
			$this->fail('Die fehlgeschlagene Erneuerung muss durchschlagen.');
		} catch (\Exception $e) {
			$this->assertStringContainsString('Wrong client_id or client_secret', $e->getMessage());
		}

		$this->assertNull($store->get(), 'Ein vom Server abgelehnter Token darf nicht im Store bleiben.');
	}

	public function testRepeatedExpiredTokenGivesUpAfterThreeAttempts(): void {
		$transport = new FakeTransport(FakeTransport::expiredToken());

		$result = $this->api($transport)->getJobs(1);

		$this->assertSame(401, $result['status_code']);
		$this->assertSame('Auth error! Max retry limit exceeded!', $result['error']);
		$this->assertSame(['response', 'status_code', 'error'], array_keys($result), 'Auch der Abbruch nutzt die normale Antwortform.');
		$this->assertCount(3, $transport->callsTo('jobs/index'));
	}

	public function testResponseEnvelopeIsUnchangedFromVersionTwo(): void {
		$transport = new FakeTransport();
		$transport->queue(new ApiResponse(200, ['status' => 'success', 'data' => ['id' => 9]]));

		$result = $this->api($transport)->getCompanyDetail(9);

		$this->assertSame(['response', 'status_code', 'error'], array_keys($result));
		$this->assertSame(['status' => 'success', 'data' => ['id' => 9]], $result['response']);
		$this->assertNull($result['error']);
	}

	public function testTransportErrorSurfacesWithoutThrowing(): void {
		$transport = new FakeTransport();
		$transport->queue(ApiResponse::transportError('Operation timed out'));

		$result = $this->api($transport)->getJobs(1);

		$this->assertSame(0, $result['status_code']);
		$this->assertSame('Operation timed out', $result['error']);
		$this->assertNull($result['response']);
	}

	public function testRespondToAppointmentUsesPost(): void {
		$transport = new FakeTransport();

		$this->api($transport)->respondToAppointment('tok', 'confirm');

		$call = $transport->callsTo('appointments/respond')[0];
		$this->assertSame('POST', $call['method']);
		$this->assertSame(['token' => 'tok', 'action' => 'confirm'], $call['payload']);
	}

	public function testAppointmentLookupUsesGet(): void {
		$transport = new FakeTransport();

		$this->api($transport)->getAppointmentByToken('tok');

		$this->assertSame('GET', $transport->callsTo('appointments/by-token')[0]['method']);
	}

	public function testApplyPostsThePayloadUntouched(): void {
		$transport = new FakeTransport();
		$payload   = ['applicant' => ['email' => 'a@b.test'], 'job_id' => 3];

		$this->api($transport)->apply($payload);

		$call = $transport->callsTo('applicant-jobs/apply')[0];
		$this->assertSame('POST', $call['method']);
		$this->assertSame($payload, $call['payload']);
	}

	public function testGetJobSendsAllThreeIdentifiers(): void {
		$transport = new FakeTransport();

		$this->api($transport)->getJob(4, 11, 22);

		$payload = $transport->callsTo('jobs/view')[0]['payload'];
		$this->assertSame(4, $payload['company']);
		$this->assertSame(11, $payload['job_id']);
		$this->assertSame(22, $payload['company_location_id']);
	}

	public function testJobsRequestIsScopedToActiveStatus(): void {
		$transport = new FakeTransport();

		$this->api($transport)->getJobs(8);

		$payload = $transport->callsTo('jobs/index')[0]['payload'];
		$this->assertSame(8, $payload['company']);
		$this->assertSame(1, $payload['status'], 'Ohne diesen Filter kaemen inaktive Anzeigen mit.');
	}

	public function testFailedAuthenticationThrows(): void {
		$transport = new FakeTransport();
		$transport->queueAuth(new ApiResponse(400, ['status' => 'error', 'errors' => 'Authentication failed. Wrong client_id or client_secret.']));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Auth error! Message from Heyrecruit: Authentication failed. Wrong client_id or client_secret.');

		$this->api($transport);
	}

	public function testUnreachableApiThrowsWithTheTransportError(): void {
		$transport = new FakeTransport();
		$transport->queueAuth(ApiResponse::transportError('Could not resolve host'));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Auth error! Message from Heyrecruit: Could not resolve host');

		$this->api($transport);
	}
}

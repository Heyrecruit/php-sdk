<?php
declare(strict_types=1);

namespace Heyrecruit\Test\Http;

use Heyrecruit\Http\CurlTransport;
use PHPUnit\Framework\TestCase;

final class CurlTransportTest extends TestCase {

	/** Ein geschlossener Port auf dem Loopback - der Connect scheitert sofort, ohne Netz. */
	private const CLOSED_ENDPOINT = 'http://127.0.0.1:9/api/v2';

	public function testTrailingSlashesAreStripped(): void {
		$this->assertSame('https://example.test/api/v2', (new CurlTransport('https://example.test/api/v2/'))->baseUrl());
		$this->assertSame('https://example.test', (new CurlTransport('https://example.test///'))->baseUrl());
	}

	public function testBaseUrlWithoutSlashIsKept(): void {
		$this->assertSame('https://example.test/api/v2', (new CurlTransport('https://example.test/api/v2'))->baseUrl());
	}

	public function testDefaultTimeoutsAreAppliedToEveryRequest(): void {
		$options = (new CurlTransport('https://example.test'))->requestOptions();

		$this->assertSame(CurlTransport::DEFAULT_CONNECT_TIMEOUT_SECONDS, $options[CURLOPT_CONNECTTIMEOUT]);
		$this->assertSame(CurlTransport::DEFAULT_REQUEST_TIMEOUT_SECONDS, $options[CURLOPT_TIMEOUT]);
	}

	public function testTimeoutsAreConfigurable(): void {
		$options = (new CurlTransport('https://example.test', 1, 2))->requestOptions();

		$this->assertSame(1, $options[CURLOPT_CONNECTTIMEOUT]);
		$this->assertSame(2, $options[CURLOPT_TIMEOUT]);
	}

	public function testPerRequestTimeoutOverridesTheDefaultButNotTheConnectTimeout(): void {
		$options = (new CurlTransport('https://example.test', 3, 4))->requestOptions([], [], 50);

		$this->assertSame(3, $options[CURLOPT_CONNECTTIMEOUT]);
		$this->assertSame(50, $options[CURLOPT_TIMEOUT]);
	}

	public function testHeadersAndReturnTransferAreAlwaysSet(): void {
		$options = (new CurlTransport('https://example.test'))->requestOptions(['X-Test: 1']);

		$this->assertTrue($options[CURLOPT_RETURNTRANSFER]);
		$this->assertSame(['X-Test: 1'], $options[CURLOPT_HTTPHEADER]);
	}

	public function testMethodSpecificOptionsWinButTimeoutsSurvive(): void {
		$options = (new CurlTransport('https://example.test', 3, 4))->requestOptions([], [
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS    => '{}',
		]);

		$this->assertSame('POST', $options[CURLOPT_CUSTOMREQUEST]);
		$this->assertSame(3, $options[CURLOPT_CONNECTTIMEOUT]);
		$this->assertSame(4, $options[CURLOPT_TIMEOUT]);
	}

	public function testFollowLocationStaysUnsetSoCredentialsAreNotForwarded(): void {
		// Ohne diese Zusage wuerde ein 302 auf einen fremden Host das client_secret mitnehmen.
		$options = (new CurlTransport('https://example.test'))->requestOptions();

		$this->assertArrayNotHasKey(CURLOPT_FOLLOWLOCATION, $options);
	}

	public function testTlsVerificationIsNotDisabled(): void {
		$options = (new CurlTransport('https://example.test'))->requestOptions();

		$this->assertArrayNotHasKey(CURLOPT_SSL_VERIFYPEER, $options);
		$this->assertArrayNotHasKey(CURLOPT_SSL_VERIFYHOST, $options);
	}

	public function testNulByteInTheUrlBecomesATransportErrorInsteadOfAValueError(): void {
		$response = (new CurlTransport("http://127.0.0.1\0/api/v2", 1, 2))->get('jobs/index');

		$this->assertSame(0, $response->statusCode);
		$this->assertNotNull($response->error);
	}

	public function testUnreachableHostBecomesATransportError(): void {
		$transport = new CurlTransport(self::CLOSED_ENDPOINT, 1, 2);

		$response = $transport->get('jobs/index');

		$this->assertSame(0, $response->statusCode);
		$this->assertNotNull($response->error);
		$this->assertNull($response->data);
		$this->assertFalse($response->isSuccess());
	}

	public function testUnreachableHostOnPostBecomesATransportError(): void {
		$transport = new CurlTransport(self::CLOSED_ENDPOINT, 1, 2);

		$response = $transport->post('applicant-jobs/apply', ['a' => 1]);

		$this->assertSame(0, $response->statusCode);
		$this->assertNotNull($response->error);
	}

	public function testPostAppliesThePerRequestTimeoutToTheRealHandle(): void {
		// Der Socket nimmt Verbindungen an (Backlog), antwortet aber nie - curl laeuft in den Gesamt-Timeout.
		$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		$this->assertNotFalse($server, $errstr);
		$transport = new CurlTransport('http://' . stream_socket_get_name($server, false), 1, 4);

		$start    = microtime(true);
		$response = $transport->post('applicant-jobs/apply', ['a' => 1], [], 1);
		$elapsed  = microtime(true) - $start;
		fclose($server);

		$this->assertSame(0, $response->statusCode);
		$this->assertLessThan(3.0, $elapsed, 'post() muss den Timeout je Request an curl weitergeben.');
	}

	public function testUnreachableHostOnAuthBecomesATransportError(): void {
		$transport = new CurlTransport(self::CLOSED_ENDPOINT, 1, 2);

		$response = $transport->postForm('auth', ['client_id' => 1, 'client_secret' => 'x']);

		$this->assertSame(0, $response->statusCode);
		$this->assertNotNull($response->error);
	}

	public function testTransportErrorNeverThrows(): void {
		$transport = new CurlTransport(self::CLOSED_ENDPOINT, 1, 2);

		$this->assertSame(0, $transport->get('anything', ['a' => 'b'], ['X-Test: 1'])->statusCode);
	}
}

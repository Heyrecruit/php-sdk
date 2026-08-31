<?php
declare(strict_types=1);

namespace Heyrecruit\Test\Http;

use Heyrecruit\Http\ApiResponse;
use PHPUnit\Framework\TestCase;

final class ApiResponseTest extends TestCase {

	public function testTwoHundredIsSuccess(): void {
		$this->assertTrue((new ApiResponse(200, ['status' => 'success']))->isSuccess());
		$this->assertTrue((new ApiResponse(204))->isSuccess());
	}

	public function testErrorsAndNonTwoHundredAreNotSuccess(): void {
		$this->assertFalse((new ApiResponse(500, ['message' => 'boom']))->isSuccess());
		$this->assertFalse((new ApiResponse(302))->isSuccess());
		$this->assertFalse(ApiResponse::transportError('timeout')->isSuccess());
	}

	public function testTransportErrorHasNoStatusAndNoBody(): void {
		$response = ApiResponse::transportError('Could not resolve host');

		$this->assertSame(0, $response->statusCode);
		$this->assertNull($response->data);
		$this->assertSame('Could not resolve host', $response->error);
	}

	public function testExpiredTokenIsRecognisedOnlyForTheMatchingBody(): void {
		$this->assertTrue((new ApiResponse(401, ['errors' => 'Expired token']))->isExpiredToken());
		$this->assertFalse((new ApiResponse(401, ['errors' => 'Invalid token']))->isExpiredToken());
		$this->assertFalse((new ApiResponse(401))->isExpiredToken());
		$this->assertFalse((new ApiResponse(200, ['errors' => 'Expired token']))->isExpiredToken());
	}

	public function testMissingErrorsKeyDoesNotWarn(): void {
		// Bis 2.x griff der Code ungeprueft auf ['response']['errors'] zu.
		$this->assertFalse((new ApiResponse(401, ['other' => 1]))->isExpiredToken());
	}

	public function testFailureDescriptionUsesTheRealApiEnvelope(): void {
		// AppService::sendJsonResponse legt Fehler unter 'errors' ab, nie unter 'message'.
		$response = new ApiResponse(400, [
			'status' => 'error',
			'errors' => 'Authentication failed. Wrong client_id or client_secret.',
			'detail' => 'Bad Request',
		]);

		$this->assertSame('Authentication failed. Wrong client_id or client_secret.', $response->describeFailure());
	}

	public function testFailureDescriptionStillAcceptsAMessageKey(): void {
		$this->assertSame('Wrong credentials', (new ApiResponse(401, ['message' => 'Wrong credentials']))->describeFailure());
	}

	public function testNonStringErrorsFallsThroughInsteadOfCastingAnArray(): void {
		// Ein Array unter 'errors' erzeugte sonst "Array to string conversion".
		$response = new ApiResponse(400, ['status' => 'error', 'errors' => ['email' => ['_empty' => 'x']]]);

		$this->assertSame('HTTP 400', $response->describeFailure());
	}

	public function testEmptyErrorsStringFallsThrough(): void {
		$this->assertSame('HTTP 400', (new ApiResponse(400, ['errors' => '']))->describeFailure());
	}

	public function testFailureDescriptionFallsBackToTheTransportError(): void {
		$this->assertSame('timeout', ApiResponse::transportError('timeout')->describeFailure());
	}

	public function testFailureDescriptionFallsBackToTheStatus(): void {
		$this->assertSame('HTTP 503', (new ApiResponse(503))->describeFailure());
	}

	public function testArrayFormMatchesTheTwoPointXEnvelope(): void {
		$response = new ApiResponse(200, ['status' => 'success', 'data' => [1, 2]]);

		$this->assertSame([
			'response'    => ['status' => 'success', 'data' => [1, 2]],
			'status_code' => 200,
			'error'       => null,
		], $response->toArray());
	}
}

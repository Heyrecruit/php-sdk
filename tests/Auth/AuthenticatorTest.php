<?php
declare(strict_types=1);

namespace Heyrecruit\Test\Auth;

use Exception;
use Heyrecruit\Auth\AccessToken;
use Heyrecruit\Auth\ArrayTokenStore;
use Heyrecruit\Auth\Authenticator;
use Heyrecruit\Http\ApiResponse;
use Heyrecruit\Test\Fake\FakeTransport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase {

	private const CONFIG = ['SCOPE_CLIENT_ID' => 42, 'SCOPE_CLIENT_SECRET' => 'SCOPE!secret'];

	public function testMissingClientIdIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing CLIENT_ID parameter.');

		new Authenticator(new FakeTransport(), new ArrayTokenStore(), ['SCOPE_CLIENT_SECRET' => 'x']);
	}

	public function testMissingClientSecretIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing CLIENT_SECRET parameter.');

		new Authenticator(new FakeTransport(), new ArrayTokenStore(), ['SCOPE_CLIENT_ID' => 1]);
	}

	public function testCredentialsAreSentToTheAuthEndpoint(): void {
		$transport = new FakeTransport();
		$auth      = new Authenticator($transport, new ArrayTokenStore(), self::CONFIG);

		$auth->token();

		$call = $transport->callsTo('auth')[0];
		$this->assertSame('POST_FORM', $call['method'], 'Der Auth-Endpunkt erwartet ein Formular-POST.');
		$this->assertSame(['client_id' => 42, 'client_secret' => 'SCOPE!secret'], $call['payload']);
	}

	public function testTokenIsRequestedOnlyOnceWhileItIsValid(): void {
		$transport = new FakeTransport();
		$auth      = new Authenticator($transport, new ArrayTokenStore(), self::CONFIG);

		$auth->token();
		$auth->token();
		$auth->token();

		$this->assertCount(1, $transport->callsTo('auth'));
	}

	public function testForceBypassesTheStore(): void {
		$transport = new FakeTransport();
		$transport->queueAuth(FakeTransport::authSuccess('first'), FakeTransport::authSuccess('second'));
		$auth = new Authenticator($transport, new ArrayTokenStore(), self::CONFIG);

		$this->assertSame('first', $auth->token()->token);
		$this->assertSame('second', $auth->token(true)->token);
		$this->assertCount(2, $transport->callsTo('auth'));
	}

	public function testAnExpiredStoredTokenIsReplaced(): void {
		$store = new ArrayTokenStore();
		$store->set(new AccessToken('stale', time() - 10));

		$transport = new FakeTransport();
		$transport->queueAuth(FakeTransport::authSuccess('fresh'));
		$auth = new Authenticator($transport, $store, self::CONFIG);

		$this->assertSame('fresh', $auth->token()->token);
	}

	public function testConstructorDoesNotDiscardAStoredToken(): void {
		// Das Portal instanziiert pro Request neu - ein Leeren im Konstruktor machte den Cache wirkungslos.
		$store = new ArrayTokenStore();
		$store->set(new AccessToken('cached', time() + 600));

		$transport = new FakeTransport();
		$auth      = new Authenticator($transport, $store, self::CONFIG);

		$this->assertSame('cached', $auth->token()->token);
		$this->assertCount(0, $transport->callsTo('auth'));
	}

	public function testChangedCredentialsDiscardTheStoredToken(): void {
		$store = new ArrayTokenStore();
		$transport = new FakeTransport();
		$auth = new Authenticator($transport, $store, self::CONFIG);
		$auth->token();

		$auth->setCredentials(['SCOPE_CLIENT_ID' => 43, 'SCOPE_CLIENT_SECRET' => 'SCOPE!other']);
		$auth->token();

		$this->assertCount(2, $transport->callsTo('auth'), 'Ein Token fuer andere Zugangsdaten darf nicht weiterverwendet werden.');
		$this->assertSame(43, $transport->callsTo('auth')[1]['payload']['client_id']);
	}

	public function testUnchangedCredentialsKeepTheStoredToken(): void {
		$store     = new ArrayTokenStore();
		$transport = new FakeTransport();
		$auth      = new Authenticator($transport, $store, self::CONFIG);
		$auth->token();

		$auth->setCredentials(self::CONFIG);
		$auth->token();

		$this->assertCount(1, $transport->callsTo('auth'));
	}

	public function testForgetForcesTheNextAuthentication(): void {
		$transport = new FakeTransport();
		$auth      = new Authenticator($transport, new ArrayTokenStore(), self::CONFIG);
		$auth->token();

		$auth->forget();
		$auth->token();

		$this->assertCount(2, $transport->callsTo('auth'));
	}

	public function testRefusedCredentialsThrowWithTheApiMessage(): void {
		$transport = new FakeTransport();
		$transport->queueAuth(new ApiResponse(400, ['status' => 'error', 'errors' => 'Authentication failed. Wrong client_id or client_secret.', 'detail' => 'Bad Request']));
		$auth = new Authenticator($transport, new ArrayTokenStore(), self::CONFIG);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Auth error! Message from Heyrecruit: Authentication failed. Wrong client_id or client_secret.');

		$auth->token();
	}

	public function testSuccessWithoutATokenThrowsInsteadOfCachingNothing(): void {
		$transport = new FakeTransport();
		$transport->queueAuth(new ApiResponse(200, ['status' => 'success', 'data' => []]));
		$auth = new Authenticator($transport, new ArrayTokenStore(), self::CONFIG);

		$this->expectException(Exception::class);

		$auth->token();
	}

	public function testNonArrayDataThrowsAnExceptionInsteadOfATypeError(): void {
		// Ein TypeError ist keine Exception - der Konsument faengt nur Exception und wuerde
		// stattdessen mit einem Fatal auf der Karriereseite landen.
		$transport = new FakeTransport();
		$transport->queueAuth(new ApiResponse(200, ['status' => 'success', 'data' => 'not-an-array']));
		$auth = new Authenticator($transport, new ArrayTokenStore(), self::CONFIG);

		$this->expectException(Exception::class);

		$auth->token();
	}

	public function testMalformedBodyThrows(): void {
		$transport = new FakeTransport();
		$transport->queueAuth(new ApiResponse(200, null, 'Malformed response body.'));
		$auth = new Authenticator($transport, new ArrayTokenStore(), self::CONFIG);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Auth error! Message from Heyrecruit: Malformed response body.');

		$auth->token();
	}

	public function testExpiryMarginIsAppliedToTheStoredToken(): void {
		$transport = new FakeTransport();
		$transport->queueAuth(FakeTransport::authSuccess('jwt', 3600));
		$auth = new Authenticator($transport, new ArrayTokenStore(), self::CONFIG);

		$token = $auth->token();

		$this->assertLessThanOrEqual(
			time() + 3600 - Authenticator::TOKEN_EXPIRY_MARGIN_SECONDS,
			$token->expiresAt
		);
	}
}

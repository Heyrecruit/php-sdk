<?php
	/**
	 * Class HeyRestApi
	 *
	 * Client for the heyrecruit rest api
	 *
	 * @author        Oleg Mutzenberger
	 * @email         oleg@artrevolver.de
	 * @web           https://scope-recruiting.de
	 * @copyright     Copyright 2024, Artrevolver GmbH
	 * @license       http://opensource.org/licenses/mit-license.php MI
	 *
	 */
	declare(strict_types=1);

	namespace Heyrecruit;

	use Exception;
	use Heyrecruit\Auth\ArrayTokenStore;
	use Heyrecruit\Auth\Authenticator;
	use Heyrecruit\Auth\SessionTokenStore;
	use Heyrecruit\Auth\TokenStore;
	use Heyrecruit\Http\ApiResponse;
	use Heyrecruit\Http\CurlTransport;
	use Heyrecruit\Http\Transport;
	use Heyrecruit\Job\JobFilter;
	use Heyrecruit\Tag\GoogleTagManager;
	use InvalidArgumentException;

	/**
	 * Fassade ueber Transport, Authentifizierung und Filter.
	 */
	class HeyRestApi {

		/** Wie oft ein Request nach einer Token-Erneuerung wiederholt wird. */
		private const MAX_AUTH_RETRIES = 3;

		/**
		 * API request url.
		 *
		 * @var string $scope_url
		 */
		public readonly string $scope_url;

		/**
		 * SCOPE API request urls.
		 *
		 * @var array<string, string> $url
		 */
		private array $url = [
			'auth'                      => 'auth',
			'get_company'               => 'companies/view',
			'get_company_by_sub_domain' => 'companies/view-by-domain',
			'get_jobs'                  => 'jobs/index',
			'get_job'                   => 'jobs/view',
			'get_appointment'           => 'appointments/by-token',
			'respond_appointment'       => 'appointments/respond',
			'apply'                     => 'applicant-jobs/apply',
		];

		private Transport $transport;

		private Authenticator $authenticator;

		private JobFilter $filter;

		/**
		 * Initializes a new instance of the SDK with the specified configuration settings.
		 *
		 * @param array           $config     Requires SCOPE_URL, SCOPE_CLIENT_ID and SCOPE_CLIENT_SECRET.
		 * @param Transport|null  $transport  Overrides the cURL transport, e.g. in tests.
		 * @param TokenStore|null $tokenStore Overrides where the access token is remembered.
		 *
		 * @throws InvalidArgumentException|Exception if the configuration settings are missing or incomplete.
		 */
		public function __construct(array $config, ?Transport $transport = null, ?TokenStore $tokenStore = null) {

			if(empty($config)) {
				throw new InvalidArgumentException('No configuration settings submitted.');
			}

			if(!isset($config['SCOPE_URL'])) {
				throw new InvalidArgumentException('Missing SCOPE_URL parameter.');
			}

			$this->scope_url = rtrim((string)$config['SCOPE_URL'], '/');
			$this->transport = $transport ?? new CurlTransport($this->scope_url);
			$this->filter    = new JobFilter();

			$this->authenticator = new Authenticator(
				$this->transport,
				$tokenStore ?? $this->defaultTokenStore($config),
				$config
			);

			$this->authenticate();
		}

		/**
		 * Sets auth data for requesting an JWT access token.
		 *
		 * @param array $config Requires SCOPE_CLIENT_ID and SCOPE_CLIENT_SECRET.
		 *
		 * @return void
		 */
		public function setAuthConfig(array $config): void {
			$this->authenticator->setCredentials($config);
		}

		/**
		 * Swaps a client_id and client_secret for an JWT auth token.
		 *
		 * @param bool $force Bypass the token store and authenticate again.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function authenticate(bool $force = false): array {
			return ['status' => 'success', 'data' => $this->authenticator->token($force)->toArray()];
		}

		/**
		 * Sets the job filter data submitted with get jobs request.
		 *
		 * Mehrfache Aufrufe ergaenzen einander, sie ersetzen nicht: ein Konsument setzt zuerst den
		 * Query-String und danach einen engeren Filter, und Werte wie language muessen erhalten bleiben.
		 *
		 * @param string $queryParams A raw query string.
		 *
		 * @return void
		 */
		public function setFilter(string $queryParams = ''): void {
			$this->filter->applyQueryString($queryParams);
		}

		/**
		 * Wie setFilter(), verwirft aber zuvor alles bisher Gesetzte.
		 *
		 * @param string $queryParams A raw query string.
		 *
		 * @return void
		 */
		public function replaceFilter(string $queryParams = ''): void {
			$this->filter = new JobFilter();
			$this->filter->applyQueryString($queryParams);
		}

		/**
		 * Submits an application.
		 *
		 * @param array $data The applicant payload.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function apply(array $data): array {
			return $this->apiRequest($this->url['apply'], $data, 'POST');
		}

		/**
		 * Get company detail.
		 *
		 * @param int $companyId The company id.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getCompanyDetail(int $companyId): array {
			return $this->apiRequest($this->url['get_company'], ['company' => $companyId]);
		}

		/**
		 * Get company detail by subdomain.
		 *
		 * @param string $subDomain The configured rest subdomain.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getCompanyDetailBySubDomain(string $subDomain): array {
			return $this->apiRequest($this->url['get_company_by_sub_domain'], ['domain' => $subDomain]);
		}

		/**
		 * Find jobs based on the pre-defined filter values.
		 *
		 * @param int|null $companyId The company id.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getJobs(?int $companyId = null): array {
			return $this->apiRequest($this->url['get_jobs'], $this->filter->toRequestData($companyId));
		}

		/**
		 * Get one job.
		 *
		 * @param int|null $companyId         The company id.
		 * @param int      $jobId             The job id.
		 * @param int      $companyLocationId The location the job belongs to.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getJob(?int $companyId, int $jobId, int $companyLocationId): array {
			return $this->apiRequest($this->url['get_job'], [
				'company'             => $companyId,
				'job_id'              => $jobId,
				'company_location_id' => $companyLocationId,
			]);
		}

		/**
		 * Get the RSVP appointment landing data for a token.
		 *
		 * @param string $token The appointment token.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getAppointmentByToken(string $token): array {
			return $this->apiRequest($this->url['get_appointment'], ['token' => $token]);
		}

		/**
		 * Send the applicant's RSVP answer for a token. POST, because it changes state - a link must not
		 * trigger it (mail scanners, prefetch).
		 *
		 * @param string $token  The appointment token.
		 * @param string $action 'confirm' or 'decline'.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function respondToAppointment(string $token, string $action): array {
			return $this->apiRequest($this->url['respond_appointment'], ['token' => $token, 'action' => $action], 'POST');
		}

		/**
		 * Generates Google Tag Manager code for the specified public ID.
		 *
		 * @param string|null $publicId The public ID of the Google Tag Manager container.
		 *
		 * @return array An associative array containing the code for the head and body sections.
		 */
		public function getGoogleTagCode(?string $publicId = ''): array {
			return GoogleTagManager::snippets($publicId);
		}

		/**
		 * Returns the authentication data for this SDK instance.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getAuthData(): array {
			return $this->authenticator->token()->toArray();
		}

		/**
		 * Performs an API request and renews the access token when the API reports it as expired.
		 *
		 * @param string $url     The endpoint path.
		 * @param array  $data    The payload or query parameters.
		 * @param string $method  The HTTP method.
		 * @param int    $attempt The current attempt, starting at 1.
		 *
		 * @return array
		 * @throws Exception
		 */
		private function apiRequest(string $url, array $data = [], string $method = 'GET', int $attempt = 1): array {
			if ($attempt > self::MAX_AUTH_RETRIES) {
				// Gleiche Antwortform wie im Normalfall - bis 2.x kam hier ein abweichendes Array.
				return (new ApiResponse(401, null, 'Auth error! Max retry limit exceeded!'))->toArray();
			}

			$token = $this->authenticator->token();

			$requestHeaders = [
				'Authorization: Bearer ' . $token->token,
				'Content-Type: application/json; charset=UTF-8',
			];

			$response = $method === 'GET'
				? $this->transport->get($url, array_merge($data, $this->requestContext()), $requestHeaders)
				: $this->transport->post($url, $data, $requestHeaders);

			if ($response->isExpiredToken()) {
				// Der Server lehnt den Token ab, obwohl die gespeicherte Laufzeit noch gilt
				// (Uhren-Differenz, rotiertes Secret) - verwerfen und neu authentifizieren.
				$this->authenticator->forget();
				$this->authenticator->token(true);

				return $this->apiRequest($url, $data, $method, $attempt + 1);
			}

			return $response->toArray();
		}

		/**
		 * Kontextfelder, die jeder GET-Request mitschickt.
		 *
		 * @return array
		 */
		private function requestContext(): array {
			return [
				// http_build_query kodiert selbst - ein zusaetzliches urlencode() ergaebe %253A statt %3A.
				'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
				'language' => $this->filter->language(),
			];
		}

		/**
		 * Ohne laufende Session gibt es keinen requestuebergreifenden Cache - dann bleibt das
		 * Token in der Instanz, damit CLI und Cron nicht pro Aufruf neu authentifizieren.
		 *
		 * @param array $config The configuration the credentials come from.
		 *
		 * @return TokenStore
		 */
		private function defaultTokenStore(array $config): TokenStore {
			if (session_status() !== PHP_SESSION_ACTIVE) {
				return new ArrayTokenStore();
			}

			// Schluessel an die Zugangsdaten binden: zwei Installationen unter einem Hostnamen teilen
			// sonst eine Session und damit den Token - eine Firma saehe die Stellen der anderen.
			$fingerprint = substr(hash(
				'sha256',
				(string)($config['SCOPE_CLIENT_ID'] ?? '') . "\0" . (string)($config['SCOPE_CLIENT_SECRET'] ?? '')
			), 0, 16);

			return new SessionTokenStore(SessionTokenStore::DEFAULT_SESSION_KEY . '_' . $fingerprint);
		}
	}

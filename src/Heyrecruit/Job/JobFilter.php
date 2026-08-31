<?php
declare(strict_types=1);

namespace Heyrecruit\Job;

/**
 * Filter fuer die Stellenliste. Uebernimmt aus einem Query-String nur die bekannten Schluessel.
 */
final class JobFilter {

	public const DEFAULT_AREA_SEARCH_DISTANCE = 60000;

	/** @var array<string, mixed> */
	private array $values = [
		'job_ids'              => [],
		'company_location_ids' => [],
		'departments'          => [],
		'employments'          => [],
		'internal_titles'      => [],
		'language'             => null,
		'search'               => null,
		'address'              => null,
		'area_search_distance' => self::DEFAULT_AREA_SEARCH_DISTANCE,
		'limit'                => 999,
		'page'                 => 1,
	];

	/** Schluessel, die genau einen Wert tragen - der Query-String liefert sie als Liste. */
	private const SINGLE_VALUE_KEYS = ['language', 'search', 'address'];

	/** Schluessel, die als Zahl an die API gehoeren. */
	private const INTEGER_KEYS = ['area_search_distance', 'limit', 'page'];

	/**
	 * @param string $queryParams A raw query string, e.g. from $_SERVER['QUERY_STRING'].
	 *
	 * @return void
	 */
	public function applyQueryString(string $queryParams): void {
		if ($queryParams === '') {
			return;
		}

		// Jeden Parameter als Liste lesen, damit wiederholte Angaben (?employments=1&employments=2) ankommen.
		$asLists = preg_replace('/(?<=^|&)(\w+)(?==)/', '$1[]', $queryParams);

		parse_str($asLists ?? $queryParams, $parsed);

		$this->values = array_replace($this->values, array_intersect_key($parsed, $this->values));

		foreach (self::SINGLE_VALUE_KEYS as $key) {
			$this->values[$key] = $this->firstValue($this->values[$key]);
		}

		foreach (self::INTEGER_KEYS as $key) {
			$this->values[$key] = max(1, (int)$this->firstValue($this->values[$key]));
		}
	}

	/**
	 * @return string|null
	 */
	public function language(): ?string {
		$language = $this->values['language'];

		return is_string($language) && $language !== '' ? $language : null;
	}

	/**
	 * Filterwerte fuer den Request, ergaenzt um Firma und Status.
	 *
	 * @param int|null $companyId The company to scope the list to.
	 * @param int      $status    The publication status expected by the API.
	 *
	 * @return array
	 */
	public function toRequestData(?int $companyId, int $status = 1): array {
		return $this->values + ['company' => $companyId, 'status' => $status];
	}

	/**
	 * @return array
	 */
	public function toArray(): array {
		return $this->values;
	}

	/**
	 * Steigt bis zum Skalar ab - ?search[0][0]=x liefert sonst weiter ein Array.
	 *
	 * @param mixed $value The raw value, possibly a nested list.
	 *
	 * @return mixed
	 */
	private function firstValue(mixed $value): mixed {
		while (is_array($value)) {
			$value = $value[0] ?? null;
		}

		return $value;
	}
}

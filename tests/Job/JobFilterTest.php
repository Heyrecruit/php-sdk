<?php
declare(strict_types=1);

namespace Heyrecruit\Test\Job;

use Heyrecruit\Job\JobFilter;
use PHPUnit\Framework\TestCase;

final class JobFilterTest extends TestCase {

	public function testDefaultsAreUsedWithoutAQueryString(): void {
		$filter = new JobFilter();

		$values = $filter->toArray();
		$this->assertSame([], $values['job_ids']);
		$this->assertNull($values['language']);
		$this->assertSame(JobFilter::DEFAULT_AREA_SEARCH_DISTANCE, $values['area_search_distance']);
		$this->assertSame(1, $values['page']);
	}

	public function testUnknownKeysAreIgnored(): void {
		$filter = new JobFilter();
		$filter->applyQueryString('company_id=99&status=all&preview=1&evil=1');

		$this->assertArrayNotHasKey('company_id', $filter->toArray());
		$this->assertArrayNotHasKey('status', $filter->toArray());
		$this->assertArrayNotHasKey('preview', $filter->toArray(), 'preview war bis 2.x ein toter Key und darf nicht wiederkommen.');
		$this->assertArrayNotHasKey('evil', $filter->toArray());
	}

	public function testRepeatedParametersBecomeLists(): void {
		$filter = new JobFilter();
		$filter->applyQueryString('employments=1&employments=2&departments=7');

		$this->assertSame(['1', '2'], $filter->toArray()['employments']);
		$this->assertSame(['7'], $filter->toArray()['departments']);
	}

	public function testSingleValueKeysAreUnwrapped(): void {
		$filter = new JobFilter();
		$filter->applyQueryString('language=de&address=Berlin&search=Entwickler');

		$values = $filter->toArray();
		$this->assertSame('de', $values['language']);
		$this->assertSame('Berlin', $values['address']);
		$this->assertSame('Entwickler', $values['search'], 'search ging bis 2.x als Array raus.');
	}

	public function testOnlyTheFirstValueOfASingleValueKeyCounts(): void {
		$filter = new JobFilter();
		$filter->applyQueryString('language=de&language=en');

		$this->assertSame('de', $filter->toArray()['language']);
	}

	public function testNumericKeysBecomeIntegers(): void {
		$filter = new JobFilter();
		$filter->applyQueryString('page=3&limit=25&area_search_distance=1000');

		$values = $filter->toArray();
		$this->assertSame(3, $values['page']);
		$this->assertSame(25, $values['limit']);
		$this->assertSame(1000, $values['area_search_distance']);
	}

	public function testNonNumericPageFallsBackToTheFirstPage(): void {
		$filter = new JobFilter();
		$filter->applyQueryString('page=abc');

		$this->assertSame(1, $filter->toArray()['page']);
	}

	public function testZeroAndNegativePageFallBackToTheFirstPage(): void {
		$filter = new JobFilter();
		$filter->applyQueryString('page=0');
		$this->assertSame(1, $filter->toArray()['page']);

		$other = new JobFilter();
		$other->applyQueryString('page=-5');
		$this->assertSame(1, $other->toArray()['page']);
	}

	public function testArrayPayloadOnASingleValueKeyDoesNotLeakAnArray(): void {
		$filter = new JobFilter();
		$filter->applyQueryString('search[]=a&search[]=b');

		$this->assertSame('a', $filter->toArray()['search']);
	}

	public function testNestedArrayPayloadIsFlattenedToAScalar(): void {
		$filter = new JobFilter();
		$filter->applyQueryString("search[0][0]=xx&address[0][0]=yy");

		$this->assertSame("xx", $filter->toArray()["search"]);
		$this->assertSame("yy", $filter->toArray()["address"]);
	}

	public function testLanguageAccessorReturnsNullForAnEmptyValue(): void {
		$filter = new JobFilter();
		$filter->applyQueryString('language=');

		$this->assertNull($filter->language());
	}

	public function testRequestDataCarriesCompanyAndStatus(): void {
		$filter = new JobFilter();

		$data = $filter->toRequestData(17);
		$this->assertSame(17, $data['company']);
		$this->assertSame(1, $data['status']);
	}

	public function testRequestDataAcceptsANullCompany(): void {
		$filter = new JobFilter();

		$this->assertNull($filter->toRequestData(null)['company']);
	}
}

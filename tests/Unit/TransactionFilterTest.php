<?php

namespace App\Tests\Unit;

use App\Dto\TransactionExportQuery;
use App\Dto\TransactionFilter;
use App\Enum\TransactionType;
use PHPUnit\Framework\TestCase;

/** Filtres des transactions : lecture tolérante des valeurs et construction des adresses. */
final class TransactionFilterTest extends TestCase
{
    public function testAnEmptyFilterFiltersNothing(): void
    {
        $filter = new TransactionFilter();

        self::assertTrue($filter->isEmpty());
        self::assertSame([], $filter->toQuery());
        self::assertSame(0, $filter->advancedCount());
        self::assertNull($filter->transactionType());
    }

    public function testAmountsAcceptACommaAndAreNormalised(): void
    {
        $filter = new TransactionFilter(min: '12,5', max: '1000');

        self::assertSame('12.50', $filter->minAmount());
        self::assertSame('1000.00', $filter->maxAmount());
    }

    public function testInvalidValuesAreIgnoredByTheAccessors(): void
    {
        $filter = new TransactionFilter(type: 'virement', from: '2026-02-30', min: 'abc', account: '12x', category: '-3');

        self::assertNull($filter->transactionType());
        self::assertNull($filter->fromDate(), 'Le 30 février n\'existe pas');
        self::assertNull($filter->minAmount());
        self::assertNull($filter->accountId());
        self::assertNull($filter->categoryId());
        self::assertSame([], $filter->toQuery());
    }

    public function testTheQueryKeepsOnlyActiveCriteria(): void
    {
        $filter = new TransactionFilter(type: 'expense', q: ' loyer ', from: '2026-11-01', to: '2026-11-30', min: '10', account: '3', category: '');

        self::assertSame([
            'type' => 'expense',
            'q' => 'loyer',
            'from' => '2026-11-01',
            'to' => '2026-11-30',
            'min' => '10.00',
            'account' => '3',
        ], $filter->toQuery());
        self::assertFalse($filter->isEmpty());
    }

    public function testWithoutRemovesSomeCriteria(): void
    {
        $filter = new TransactionFilter(type: 'income', from: '2026-11-01', to: '2026-11-30', account: '3');

        self::assertSame(['type' => 'income', 'account' => '3'], $filter->without(['from', 'to']));
        self::assertSame(['from' => '2026-11-01', 'to' => '2026-11-30', 'account' => '3'], $filter->without(['type']));
    }

    public function testAdvancedCriteriaAreCountedByGroup(): void
    {
        self::assertSame(1, (new TransactionFilter(from: '2026-11-01', to: '2026-11-30'))->advancedCount(), 'Une période = un filtre');
        self::assertSame(1, (new TransactionFilter(min: '5', max: '50'))->advancedCount(), 'Une fourchette = un filtre');
        self::assertSame(4, (new TransactionFilter(from: '2026-11-01', max: '50', account: '1', category: '2'))->advancedCount());
        self::assertSame(0, (new TransactionFilter(type: 'income', q: 'x'))->advancedCount(), 'Type et recherche ont leur propre barre');
    }

    public function testTheTypeIsReadAsAnEnum(): void
    {
        self::assertSame(TransactionType::Expense, (new TransactionFilter(type: 'expense'))->transactionType());
    }

    public function testTheExportQueryAddsTheFormatToTheSameFilters(): void
    {
        $query = new TransactionExportQuery(type: 'income', min: '20', account: '4', format: 'intl');

        self::assertSame('intl', $query->format);
        self::assertSame(['type' => 'income', 'min' => '20.00', 'account' => '4'], $query->toQuery());
        self::assertInstanceOf(TransactionFilter::class, $query);
    }
}

<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Differ;

use zeixcom\craftdelta\differ\TableDiffer;
use PHPUnit\Framework\TestCase;

class TableDifferTest extends TestCase
{
    private TableDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new TableDiffer();
    }

    public function testIdenticalRowsReturnNull(): void
    {
        $rows = [
            ['col1' => 'a', 'col2' => 'b'],
            ['col1' => 'c', 'col2' => 'd'],
        ];
        $this->assertNull($this->differ->diff($rows, $rows));
    }

    public function testEmptyArraysReturnNull(): void
    {
        $this->assertNull($this->differ->diff([], []));
    }

    public function testNullValuesReturnNull(): void
    {
        $this->assertNull($this->differ->diff(null, null));
    }

    public function testAddedRowDetected(): void
    {
        $old = [['col1' => 'a']];
        $new = [['col1' => 'a'], ['col1' => 'b']];
        $result = $this->differ->diff($old, $new);
        $this->assertNotNull($result);

        $changes = json_decode($result, true);
        $this->assertCount(1, $changes);
        $this->assertSame('added', $changes[0]['type']);
        $this->assertSame(2, $changes[0]['row']);
        $this->assertSame(['col1' => 'b'], $changes[0]['values']);
    }

    public function testRemovedRowDetected(): void
    {
        $old = [['col1' => 'a'], ['col1' => 'b']];
        $new = [['col1' => 'a']];
        $result = $this->differ->diff($old, $new);
        $this->assertNotNull($result);

        $changes = json_decode($result, true);
        $this->assertCount(1, $changes);
        $this->assertSame('removed', $changes[0]['type']);
        $this->assertSame(2, $changes[0]['row']);
    }

    public function testModifiedRowWithCellDiffs(): void
    {
        $old = [['col1' => 'a', 'col2' => 'b']];
        $new = [['col1' => 'a', 'col2' => 'changed']];
        $result = $this->differ->diff($old, $new);
        $this->assertNotNull($result);

        $changes = json_decode($result, true);
        $this->assertCount(1, $changes);
        $this->assertSame('modified', $changes[0]['type']);
        $this->assertSame(1, $changes[0]['row']);

        // Cell-level diff
        $cells = $changes[0]['cells'];
        $this->assertCount(1, $cells);
        $this->assertSame('col2', $cells[0]['col']);
        $this->assertSame('b', $cells[0]['old']);
        $this->assertSame('changed', $cells[0]['new']);
    }

    public function testMultipleCellChangesInOneRow(): void
    {
        $old = [['col1' => 'a', 'col2' => 'b', 'col3' => 'c']];
        $new = [['col1' => 'x', 'col2' => 'b', 'col3' => 'z']];
        $result = $this->differ->diff($old, $new);

        $changes = json_decode($result, true);
        $cells = $changes[0]['cells'];
        $this->assertCount(2, $cells);

        $changedCols = array_column($cells, 'col');
        $this->assertContains('col1', $changedCols);
        $this->assertContains('col3', $changedCols);
    }

    public function testMixedAddRemoveModify(): void
    {
        $old = [
            ['col1' => 'a'],
            ['col1' => 'b'],
            ['col1' => 'c'],
        ];
        $new = [
            ['col1' => 'a'],
            ['col1' => 'CHANGED'],
        ];
        $result = $this->differ->diff($old, $new);

        $changes = json_decode($result, true);
        $this->assertCount(2, $changes);

        // Row 2 modified
        $this->assertSame('modified', $changes[0]['type']);
        $this->assertSame(2, $changes[0]['row']);

        // Row 3 removed
        $this->assertSame('removed', $changes[1]['type']);
        $this->assertSame(3, $changes[1]['row']);
    }

    public function testEmptyToRowsProducesDiff(): void
    {
        $new = [['col1' => 'a']];
        $result = $this->differ->diff([], $new);
        $this->assertNotNull($result);

        $changes = json_decode($result, true);
        $this->assertSame('added', $changes[0]['type']);
    }

    public function testRowsToEmptyProducesDiff(): void
    {
        $old = [['col1' => 'a']];
        $result = $this->differ->diff($old, []);
        $this->assertNotNull($result);

        $changes = json_decode($result, true);
        $this->assertSame('removed', $changes[0]['type']);
    }

    public function testStatsAddedRows(): void
    {
        $old = [['col1' => 'a']];
        $new = [['col1' => 'a'], ['col1' => 'b'], ['col1' => 'c']];
        $stats = $this->differ->getStats($old, $new);
        $this->assertSame(2, $stats['additions']);
        $this->assertSame(0, $stats['deletions']);
    }

    public function testStatsRemovedRows(): void
    {
        $old = [['col1' => 'a'], ['col1' => 'b']];
        $new = [];
        $stats = $this->differ->getStats($old, $new);
        $this->assertSame(0, $stats['additions']);
        $this->assertSame(2, $stats['deletions']);
    }

    public function testStatsIdentical(): void
    {
        $rows = [['col1' => 'a']];
        $stats = $this->differ->getStats($rows, $rows);
        $this->assertSame(0, $stats['additions']);
        $this->assertSame(0, $stats['deletions']);
    }

    public function testNewColumnInRow(): void
    {
        $old = [['col1' => 'a']];
        $new = [['col1' => 'a', 'col2' => 'new']];
        $result = $this->differ->diff($old, $new);
        $this->assertNotNull($result);

        $changes = json_decode($result, true);
        $cells = $changes[0]['cells'];
        $this->assertSame('col2', $cells[0]['col']);
        $this->assertSame('', $cells[0]['old']);
        $this->assertSame('new', $cells[0]['new']);
    }
}

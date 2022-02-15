<?php

namespace Glhd\ConveyorBelt\Tests;

use Glhd\ConveyorBelt\Tests\Commands\TestJsonFileCommand;
use Glhd\ConveyorBelt\Tests\Commands\TestQueryCommand;
use Glhd\ConveyorBelt\Tests\Concerns\TestsCommonCommandVariations;
use Glhd\ConveyorBelt\Tests\Concerns\TestsDatabaseTransactions;
use Glhd\ConveyorBelt\Tests\Concerns\TestsStepMode;
use Glhd\ConveyorBelt\Tests\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SqlFormatter;

class IteratesQueryTest extends DatabaseTestCase
{
	use TestsDatabaseTransactions;
	use TestsCommonCommandVariations;
	
	/** @dataProvider dataProvider */
	public function test_it_iterates_database_queries(string $case, bool $step, $exceptions, bool $transaction): void
	{
		$expectations = [
			'Bogdan Kharchenko',
			'Chris Morrell',
			'Mohamed Said',
			'Taylor Otwell',
		];
		
		$this->registerHandleRowCallback(function($row) use (&$expectations, $case, $exceptions) {
			$expected = array_shift($expectations);
			$this->assertEquals($expected, $row->name);
			
			if ('eloquent' === $case) {
				$this->assertInstanceOf(User::class, $row);
			}
			
			if ($exceptions && 'Chris Morrell' === $row->name) {
				throw new RuntimeException('This should be caught.');
			}
		});
		
		$command = $this->setUpCommandWithCommonAssertions($exceptions, $step, TestQueryCommand::class, [
			'case' => $case,
			'--transaction' => $transaction,
		]);
		
		$command->run();
		
		if ($transaction) {
			$this->assertDatabaseTransactionWasCommitted();
		}
		
		$this->assertEmpty($expectations);
		$this->assertHookMethodsWereCalledInExpectedOrder();
	}
	
	public function dataProvider()
	{
		$cases = [
			'eloquent',
			'base',
		];
		
		foreach ($cases as $case) {
			foreach ([false, true] as $step) {
				foreach ([false, 'throw', 'collect'] as $exceptions) {
					foreach ([false, true] as $transaction) {
						$label = (implode('; ', array_filter([
							$case,
							$step
								? 'step mode'
								: null,
							$exceptions
								? "{$exceptions} exceptions"
								: null,
							$transaction
								? 'in transaction'
								: null,
						])));
						
						yield $label => [$case, $step, $exceptions, $transaction];
					}
				}
			}
		}
	}
	
	public function test_dump_sql(): void
	{
		$formatted = SqlFormatter::format('select * from "users" order by "name" asc');
		
		$this->artisan(TestQueryCommand::class, ['case' => 'eloquent', '--dump-sql' => true])
			->expectsOutput($formatted)
			->assertFailed();
	}
}

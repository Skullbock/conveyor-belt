<?php

namespace Glhd\ConveyorBelt\Tests;

use Glhd\ConveyorBelt\Tests\Commands\TestIdQueryCommand;
use Glhd\ConveyorBelt\Tests\Concerns\TestsDatabaseTransactions;
use Glhd\ConveyorBelt\Tests\Models\User;
use RuntimeException;

class IteratesIdQueryTest extends DatabaseTestCase
{
	use TestsDatabaseTransactions;
	
	/** @dataProvider dataProvider */
	public function test_it_iterates_database_queries(string $case, bool $step, $exceptions, bool $transaction): void
	{
		$expectations = [
			'Chris Morrell',
			'Bogdan Kharchenko',
			'Taylor Otwell',
			'Mohamed Said',
		];
		
		$this->registerHandleRowCallback(function($row) use (&$expectations, $case, $exceptions) {
			$expected = array_shift($expectations);
			$this->assertEquals($expected, $row->name);
			
			if ('eloquent' === $case) {
				$this->assertInstanceOf(User::class, $row);
			}
			
			if ($exceptions && 'Bogdan Kharchenko' === $row->name) {
				throw new RuntimeException('This should be caught.');
			}
		});
		
		if ('throw' === $exceptions) {
			$this->expectException(RuntimeException::class);
		}
		
		$command = $this->artisan(TestIdQueryCommand::class, [
			'case' => $case,
			'--step' => $step,
			'--throw' => 'throw' === $exceptions,
			'--transaction' => $transaction,
		]);
		
		if ($step && 'throw' === $exceptions) {
			// If we're throwing exceptions, we'll only have 1 successful iteration
			$command->expectsQuestion('Continue?', true);
		} elseif ($step) {
			// Otherwise we should have 4 iterations
			foreach (range(1, 4) as $_) {
				$command->expectsQuestion('Continue?', true);
			}
		}
		
		if ($exceptions) {
			$command->assertFailed();
		} else {
			$command->assertSuccessful();
		}
		
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
}

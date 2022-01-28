<?php

namespace Glhd\ConveyorBelt\Support;

use Closure;
use Glhd\ConveyorBelt\Exceptions\AbortConveyorBeltException;
use Illuminate\Console\Concerns\InteractsWithIO;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SqlFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\OutputStyle;
use Throwable;

class ConveyorBelt
{
	use InteractsWithIO;
	
	public ProgressBar $progress;
	
	/** @var \Glhd\ConveyorBelt\IteratesQuery|\Illuminate\Console\Command */
	protected $command;
	
	protected $query = null;
	
	/** @var \Glhd\ConveyorBelt\Support\CollectedException[] */
	protected array $exceptions = [];
	
	public function __construct($command)
	{
		$this->command = $command;
		
		$this->addConveyorBeltOptions();
	}
	
	public function initialize(InputInterface $input, OutputStyle $output): void
	{
		$this->input = $input;
		$this->output = $output;
		
		$this->progress = new ProgressBar($input, $output);
	}
	
	public function run(): int
	{
		try {
			$this->prepare();
			$this->printIntro();
			$this->start();
			$this->finish();
			
			return Command::SUCCESS;
		} catch (AbortConveyorBeltException $exception) {
			if (! empty($message = $exception->getMessage())) {
				$this->error($message);
			}
			
			return $exception->getCode();
		}
	}
	
	protected function prepare(): void
	{
		$this->verifyCommandSetup();
		$this->prepareForQueryLogging();
		$this->setVerbosityBasedOnStepMode();
		
		// The "before first row" hook should run before the --dump-sql flag
		// is checked, just in case the command needs to set up any data that
		// will be used to build the query (i.e. other inputs or environmental data)
		$this->command->beforeFirstRow();
		
		// Once everything else is prepared, we'll check for the --dump-sql
		// flag and if it's set, print the query and exit
		$this->dumpSqlAndAbortIfRequested();
	}
	
	protected function start(): void
	{
		if (! $count = $this->query()->count()) {
			$this->command->info("There are no {$this->command->rowNamePlural()} that match your query.");
			return;
		}
		
		$this->progress->start($count, $this->command->rowName());
		
		if ($this->command->useTransaction()) {
			DB::transaction(fn() => $this->executeQuery());
		} else {
			$this->executeQuery();
		}
		
		$this->progress->finish();
	}
	
	protected function finish(): void
	{
		$this->command->afterLastRow();
		
		$this->showSummary();
	}
	
	protected function abort(string $message = '', int $code = Command::FAILURE): void
	{
		throw new AbortConveyorBeltException($message, $code);
	}
	
	protected function executeQuery(): void
	{
		$this->command->beforeFirstQuery();
		
		$this->command->iterateOverQuery($this->query(), $this->getChunkHandler());
	}
	
	protected function getChunkHandler(): Closure
	{
		return function($items) {
			$this->command->prepareChunk($items);
			
			foreach ($items as $item) {
				if (false === $this->presentRow($item)) {
					return false;
				}
			}
			
			return true;
		};
	}
	
	protected function presentRow($item): bool
	{
		try {
			$original = $this->getOriginalForDiff($item);
			$this->command->handleRow($item);
		} catch (Throwable $throwable) {
			$this->handleRowException($throwable, $item);
		}
		
		$this->progress->advance();
		
		$this->logSql();
		$this->logDiff($item, $original);
		$this->pauseIfStepping();
		
		return true;
	}
	
	protected function handleRowException(Throwable $exception, $item): void
	{
		if (! $this->command->collectExceptions()) {
			$this->progress->finish();
			
			throw $exception;
		}
		
		$this->exceptions[] = new CollectedException(
			$exception,
			$this->getOutput()->isVerbose(),
			$this->command->rowName(),
			$item
		);
	}
	
	protected function logSql(): void
	{
		if (! $this->option('log-sql')) {
			return;
		}
		
		$this->getOutput()->section('SQL Queries Executed');
		
		collect(DB::getQueryLog())
			->each(fn($log) => $this->printFormattedQuery($log['query'], $log['bindings']));
		
		$this->newLine();
		
		DB::flushQueryLog();
	}
	
	protected function getOriginalForDiff($item): array
	{
		if (! $item instanceof Model || ! $this->option('diff')) {
			return [];
		}
		
		return $item->getOriginal();
	}
	
	protected function logDiff($item, array $original): void
	{
		if (! $this->option('diff')) {
			return;
		}
		
		if (! $item instanceof Model) {
			$this->abort('The --diff flag requires Eloquent models');
		}
		
		$table = array_map(
			fn($value, $key) => [$key, $original[$key] ?? null, $value], 
			$item->getChanges()
		);
		
		$this->getOutput()->section('Changes to '.Str::title($this->command->rowName()));
		$this->table(['', 'Original', 'Updated'], $table);
		$this->newLine();
	}
	
	protected function pauseIfStepping(): void
	{
		if ($this->option('step') && ! $this->confirm('Continue?', true)) {
			$this->abort('Operation cancelled.');
		}
	}
	
	protected function verifyCommandSetup(): void
	{
		if (! method_exists($this->command, 'handleRow')) {
			$this->abort('You must implement '.class_basename($this->command).'::handleRow()', Command::INVALID);
		}
	}
	
	protected function prepareForQueryLogging(): void
	{
		if ($this->option('log-sql')) {
			$this->input->setOption('step', true);
			DB::enableQueryLog();
		}
	}
	
	protected function setVerbosityBasedOnStepMode(): void
	{
		if ($this->option('step')) {
			$this->output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
		}
	}
	
	protected function dumpSqlAndAbortIfRequested(): void
	{
		if (! $this->option('dump-sql')) {
			return;
		}
		
		$query = $this->query();
		$this->printFormattedQuery($query->toSql(), $query->getBindings());
		
		$this->abort();
	}
	
	protected function printFormattedQuery(string $sql, array $bindings): void
	{
		$bindings = Arr::flatten($bindings);
		
		$sql = preg_replace_callback('/\?/', static function() use (&$bindings) {
			return DB::getPdo()->quote(array_shift($bindings));
		}, $sql);
		
		$this->newLine();
		$this->line(SqlFormatter::format($sql));
	}
	
	protected function printIntro(): void
	{
		$transaction_status = $this->command->useTransaction()
			? '(using a database transaction)'
			: '(no database transaction)';
		
		$this->info("Querying {$this->command->rowNamePlural()} {$transaction_status}...");
	}
	
	protected function showSummary(): void
	{
		if (count($this->exceptions)) {
			$this->newLine();
			
			$this->getOutput()->section('Exceptions Triggered During Run');
			
			foreach ($this->exceptions as $exception) {
				$this->error($exception);
				$this->newLine();
			}
			
			$this->abort();
		}
	}
	
	protected function addConveyorBeltOptions(): void
	{
		$definition = $this->command->getDefinition();
		
		$definition->addOption(new InputOption('dump-sql'));
		$definition->addOption(new InputOption('log-sql'));
		$definition->addOption(new InputOption('step'));
		$definition->addOption(new InputOption('diff'));
	}
	
	/**
	 * @return BaseBuilder|EloquentBuilder|Relation
	 */
	protected function query()
	{
		return $this->query ??= $this->fetchQueryFromCommand();
	}
	
	protected function fetchQueryFromCommand()
	{
		if (! method_exists($this->command, 'query')) {
			$this->abort('You must implement '.class_basename($this->command).'::query()', Command::INVALID);
		}
		
		$query = $this->command->query();
		
		$expected = [
			BaseBuilder::class,
			EloquentBuilder::class,
			Relation::class,
		];
		
		foreach ($expected as $name) {
			if ($query instanceof $name) {
				return $query;
			}
		}
		
		$this->abort(class_basename($this->command).'::query() must return a query builder', Command::INVALID);
	}
}

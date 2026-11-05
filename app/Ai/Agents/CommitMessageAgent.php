<?php

namespace App\Ai\Agents;

use App\Support\GitRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\FinishReason;
use Stringable;
use Throwable;

/**
 * Writes a short commit subject from the staged changes of a repository.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-5-5')]
#[MaxTokens(4096)]
#[Timeout(30)]
class CommitMessageAgent implements Agent, HasProviderOptions
{
    use Promptable;

    /**
     * The longest diff sent to the model, in characters.
     */
    private const MAX_DIFF_LENGTH = 30_000;

    /**
     * The longest commit subject that is kept, in characters.
     */
    private const MAX_SUBJECT_LENGTH = 100;

    /**
     * Check whether an Anthropic API key is configured.
     */
    public static function isConfigured(): bool
    {
        return filled(config('ai.providers.anthropic.key'));
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You write git commit messages for a developer who maintains many Laravel and WordPress sites.
            Reply with one commit subject line and nothing else: English, imperative mood, sentence case, at most 72 characters, no trailing period, no quotes, no markdown and no conventional-commit prefix.
            Describe what the change does for the project, not which files it touches.
            When only dependency lock files or manifests changed, say that the dependencies were updated, naming the ecosystem (Composer, npm) when it is clear.
            Never use em dashes or en dashes.
            TEXT;
    }

    /**
     * A one-line subject needs little thinking.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return ['output_config' => ['effort' => 'low']];
    }

    /**
     * Write a commit subject for the staged changes, or null when Claude is not configured or gives no usable answer.
     */
    public function describe(GitRepository $repository): ?string
    {
        if (! self::isConfigured()) {
            return null;
        }

        $stat = $repository->stagedDiffStat();

        if ($stat === '') {
            return null;
        }

        try {
            $response = $this->prompt($this->promptFor($stat, $repository->stagedDiffWithoutLockFiles()));
        } catch (Throwable $exception) {
            Log::warning('Claude could not write a commit message', ['error' => $exception->getMessage()]);

            return null;
        }

        $finishReason = $response->steps->last()?->finishReason;

        if (in_array($finishReason, [FinishReason::ContentFilter, FinishReason::Length], true)) {
            Log::warning('Claude gave no usable commit message', ['finish_reason' => $finishReason->value]);

            return null;
        }

        return $this->subjectFrom($response->text);
    }

    /**
     * Build the prompt from the file stat and the diff.
     */
    private function promptFor(string $stat, string $diff): string
    {
        $diff = $diff === ''
            ? 'Only lock files changed.'
            : Str::limit($diff, self::MAX_DIFF_LENGTH, "\n[diff cut off]");

        return "Changed files:\n{$stat}\n\nDiff (lock files left out):\n{$diff}";
    }

    /**
     * Reduce the answer to a single clean subject line.
     */
    private function subjectFrom(string $text): ?string
    {
        $firstLine = Str::of($text)->trim()->explode("\n")
            ->map(fn (string $line): string => trim($line))
            ->first(fn (string $line): bool => $line !== '', '');

        $subject = Str::of($firstLine)
            ->replace(["\u{2014}", "\u{2013}"], '-')
            ->trim(" \t\"'`*")
            ->rtrim('.')
            ->limit(self::MAX_SUBJECT_LENGTH, '')
            ->trim()
            ->toString();

        return $subject === '' ? null : $subject;
    }
}

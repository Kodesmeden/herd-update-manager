<?php

use App\Ai\Agents\CommitMessageAgent;
use App\Support\GitRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Fake the staged changes of a repository.
 */
function fakeStagedChanges(string $stat, string $diff): void
{
    Process::fake([
        'git diff --cached --stat*' => Process::result($stat),
        'git diff --cached --no-color*' => Process::result($diff),
    ]);
}

it('writes a commit subject from the staged changes', function () {
    config(['ai.providers.anthropic.key' => 'test-key']);
    fakeStagedChanges(' app/Models/User.php | 2 +-', "+    protected \$hidden = ['password'];");
    CommitMessageAgent::fake(['Hide the password when serializing users']);

    expect((new CommitMessageAgent)->describe(new GitRepository(base_path())))
        ->toBe('Hide the password when serializing users');

    CommitMessageAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('app/Models/User.php | 2 +-')
        && $prompt->contains("protected \$hidden = ['password'];"));
});

it('asks Haiku at low effort and names the workspace', function () {
    config([
        'ai.providers.anthropic.key' => 'test-key',
        'ai.providers.anthropic.headers' => ['anthropic-workspace-id' => 'wrkspc_test'],
    ]);
    fakeStagedChanges(' routes/web.php | 1 +', '+Route::get(...)');
    Http::preventStrayRequests();
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => 'Add the settings route']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 40, 'output_tokens' => 6],
        ]),
    ]);

    expect((new CommitMessageAgent)->describe(new GitRepository(base_path())))->toBe('Add the settings route');

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'claude-haiku-5-5'
        && $request['output_config']['effort'] === 'low'
        && $request->hasHeader('anthropic-workspace-id', 'wrkspc_test'));
});

it('leaves lock files out of the diff it sends', function () {
    config(['ai.providers.anthropic.key' => 'test-key']);
    fakeStagedChanges(" composer.lock | 120 +++---\n package-lock.json | 80 ++--", '');
    CommitMessageAgent::fake(['Update Composer and npm dependencies']);

    (new CommitMessageAgent)->describe(new GitRepository(base_path()));

    Process::assertRan(fn ($process): bool => str_starts_with($process->command, 'git diff --cached --no-color')
        && str_contains($process->command, "':(exclude,glob)**/composer.lock'")
        && str_contains($process->command, "':(exclude,glob)**/package-lock.json'"));
    CommitMessageAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Only lock files changed.'));
});

it('cuts off a diff that is too long to send', function () {
    config(['ai.providers.anthropic.key' => 'test-key']);
    fakeStagedChanges(' resources/js/app.tsx | 900 +++', str_repeat('x', 40_000));
    CommitMessageAgent::fake(['Rewrite the app shell']);

    (new CommitMessageAgent)->describe(new GitRepository(base_path()));

    CommitMessageAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('[diff cut off]')
        && ! $prompt->contains(str_repeat('x', 30_001)));
});

it('reduces the answer to one clean subject line', function (string $answer, string $subject) {
    config(['ai.providers.anthropic.key' => 'test-key']);
    fakeStagedChanges(' routes/web.php | 1 +', '+Route::get(...)');
    CommitMessageAgent::fake([$answer]);

    expect((new CommitMessageAgent)->describe(new GitRepository(base_path())))->toBe($subject);
})->with([
    'quoted with a trailing period' => ['"Add the settings route."', 'Add the settings route'],
    'followed by an explanation' => ["\nAdd the settings route\n\nThis registers a GET route.", 'Add the settings route'],
    'with an em dash' => ["Add the settings route \u{2014} for admins", 'Add the settings route - for admins'],
    'in markdown' => ['**`Add the settings route`**', 'Add the settings route'],
]);

it('writes nothing when no API key is configured', function () {
    config(['ai.providers.anthropic.key' => null]);
    fakeStagedChanges(' routes/web.php | 1 +', '+Route::get(...)');
    CommitMessageAgent::fake();

    expect((new CommitMessageAgent)->describe(new GitRepository(base_path())))->toBeNull();

    CommitMessageAgent::assertNeverPrompted();
});

it('writes nothing when no changes are staged', function () {
    config(['ai.providers.anthropic.key' => 'test-key']);
    fakeStagedChanges('', '');
    CommitMessageAgent::fake();

    expect((new CommitMessageAgent)->describe(new GitRepository(base_path())))->toBeNull();

    CommitMessageAgent::assertNeverPrompted();
});

it('writes nothing when the request to Claude fails', function () {
    config(['ai.providers.anthropic.key' => 'test-key']);
    fakeStagedChanges(' routes/web.php | 1 +', '+Route::get(...)');
    CommitMessageAgent::fake(fn () => throw new RuntimeException('Overloaded'));

    expect((new CommitMessageAgent)->describe(new GitRepository(base_path())))->toBeNull();
});

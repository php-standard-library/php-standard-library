<?php

declare(strict_types=1);

namespace Psl\Splitter;

use Psl\Async;
use Psl\Env;
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\IO;
use Psl\Json;
use Psl\Str;
use Psl\Type;
use Psl\URL;

/**
 * Tag all split repos at the HEAD of a branch using the GitHub API.
 *
 * Creates tags via the API rather than raw git push, so GitHub marks them as "verified".
 *
 * @param non-empty-string $tag The tag name (e.g. "6.0.0")
 * @param non-empty-string $branch The branch whose HEAD to tag (e.g. "next", "6.0.x")
 */
function tag(Client\Client $httpClient, MonolithicRepository $monorepo, Git $git, string $tag, string $branch): void
{
    $token = Env\get_var('GITHUB_TOKEN') ?? '';

    $headers = Message\FieldMap::from([
        ['authorization', 'Bearer ' . $token],
        ['accept', 'application/vnd.github+json'],
        ['x-github-api-version', '2022-11-28'],
        ['content-type', 'application/json'],
    ]);

    $semaphore = new Async\Semaphore(20, static function (Package $package) use (
        $git,
        $tag,
        $branch,
        $token,
        $headers,
        $httpClient,
    ): void {
        $repoUrl = $package->repositoryUrl();

        // Get the SHA of the branch HEAD
        $lsRemote = Str\trim($git->lsRemote($repoUrl, 'refs/heads/' . $branch));
        if ($lsRemote === '') {
            Log\warn('%s branch %s not found, skipping', $package->name, $branch);
            return;
        }

        $sha = Str\before($lsRemote, "\t") ?? $lsRemote;

        if ($token !== '') {
            $repo = Str\after($package->name, '/') ?? $package->name;
            $org = Str\before($package->name, '/') ?? 'php-standard-library';
            $transaction = $httpClient->send(new Message\Request(
                method: Message\METHOD_POST,
                url: URL\parse(Str\format('https://api.github.com/repos/%s/%s/git/tags', $org, $repo)),
                headers: $headers,
                body: new IO\MemoryHandle(Json\encode([
                    'tag' => $tag,
                    'message' => $tag,
                    'object' => $sha,
                    'type' => 'commit',
                ])),
            ));

            $content = $transaction->response->body?->readAll() ?? '';
            if ($transaction->response->status >= 300) {
                $body = Json\typed($content, Type\shape([
                    'message' => Type\string(),
                ], allowUnknownFields: true));

                Log\error('%s tag object failed: %s', $package->name, $body['message']);
                return;
            }

            $tagSha = Json\typed($content, Type\shape([
                'sha' => Type\non_empty_string(),
            ], allowUnknownFields: true))['sha'];

            $transaction = $httpClient->send(new Message\Request(
                method: Message\METHOD_POST,
                url: URL\parse(Str\format('https://api.github.com/repos/%s/%s/git/refs', $org, $repo)),
                headers: $headers,
                body: new IO\MemoryHandle(Json\encode([
                    'ref' => 'refs/tags/' . $tag,
                    'sha' => $tagSha,
                ])),
            ));

            if ($transaction->response->status >= 300) {
                $content = $transaction->response->body?->readAll() ?? '';
                $body = Json\typed($content, Type\shape([
                    'message' => Type\string(),
                ], allowUnknownFields: true));

                Log\error('%s tag ref failed: %s', $package->name, $body['message']);
                return;
            }
        } else {
            Log\warn('github token not set, falling back to `git push`');
            $git->tagRemote($repoUrl, $tag, $branch);
        }

        Log\success('%s tag %s', $package->name, $tag);
    });

    $awaitables = [];
    foreach ($monorepo->packages as $package) {
        $awaitables[] = Async\run(static fn() => $semaphore->waitFor($package));
    }

    Async\all($awaitables);
}

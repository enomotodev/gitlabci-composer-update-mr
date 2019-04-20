<?php

namespace Enomotodev\GitLabCIComposerUpdateMr;

use Exception;
use DateTime;
use Gitlab\Client;

class Command
{
    /**
     * @return void
     */
    public static function main()
    {
        $command = new static;

        $command->run($_SERVER['argv']);
    }

    /**
     * @param  array $argv
     * @return void
     *
     * @throws Exception
     */
    public function run($argv)
    {
        if (count($argv) !== 4) {
            fwrite(STDERR, 'Invalid arguments.' . PHP_EOL);

            exit(1);
        }

        list(, $name, $email, $base) = $argv;

        system('composer update --no-progress --no-suggest');

        $now = new DateTime('now');
        $branch = 'composer-update-' . $now->format('YmdHis');

        if (strpos(system('git status -sb'), 'composer.lock') === false) {
            fwrite(STDOUT, 'No changes.' . PHP_EOL);
            exit(0);
        }

        $this->setupGit($name, $email);

        $json = system('$COMPOSER_HOME/vendor/bin/composer-lock-diff --json');
        $diff = json_decode($json, true);

        $description = $this->createMergeRequestDescription($diff);

        $this->createBranch($branch);
        $this->createMergeRequest($base, $branch, $now, $description);
    }

    /**
     * @param  string $name
     * @param  string $email
     * @return void
     */
    private function setupGit($name, $email)
    {
        system("git config user.name {$name}");
        system("git config user.email {$email}");

        $token = getenv('GITLAB_API_PRIVATE_TOKEN');
        $repositoryUrl = getenv('CI_REPOSITORY_URL');
        preg_match('/https:\/\/gitlab-ci-token:(.*)@(.*)/', $repositoryUrl, $matches);
        system("git remote set-url origin \"https://gitlab-ci-token:{$token}@{$matches[2]}\"");
    }

    /**
     * @param  string $branch
     * @return void
     */
    private function createBranch($branch)
    {
        system("git checkout -b {$branch}");
        system("git add composer.lock");
        system("git commit -m '$ composer update'");
        system("git push origin {$branch}");
    }

    /**
     * @param  string $base
     * @param  string $branch
     * @param  \DateTime $now
     * @param  string $description
     * @return void
     */
    private function createMergeRequest($base, $branch, $now, $description)
    {
        $token = getenv('GITLAB_API_PRIVATE_TOKEN');
        $projectId = getenv('CI_PROJECT_ID');
        $projectPath = getenv('CI_PROJECT_PATH');
        $repositoryUrl = getenv('CI_REPOSITORY_URL');
        $ciUrl = str_replace("{$projectPath}.git", '', $repositoryUrl);

        $title = 'composer update at ' . $now->format('Y-m-d H:i:s T');

        $httpClient = \Http\Adapter\Guzzle6\Client::createWithConfig(['verify' => false]);
        $client = Client::createWithHttpClient($httpClient)
            ->setUrl($ciUrl)
            ->authenticate($token, Client::AUTH_URL_TOKEN);
        /** @var \Gitlab\Api\MergeRequests $api */
        $api = $client->api('merge_requests');
        try {
            $api->create(
                $projectId,
                $branch,
                $base,
                $title,
                null,
                null,
                '## Updated Composer Packages' . PHP_EOL . PHP_EOL . $description
            );
        } catch (Exception $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }

    /**
     * @param  array $diff
     * @return string
     */
    private function createMergeRequestDescription($diff)
    {
        $description = '';
        foreach (['changes', 'changes-dev'] as $key) {
            if (!empty($diff[$key])) {
                $description .= "### {$key}" . PHP_EOL;
                foreach ($diff[$key] as $packageName => $value) {
                    $description .= "- {$packageName}: ";
                    if ($value[2]) {
                        $description .= "[`{$value[0]}...{$value[1]}`]({$value[2]})";
                    } else {
                        $description .= "`{$value[0]}...{$value[1]}`";
                    }
                    $description .= PHP_EOL;
                }
                $description .= PHP_EOL;
            }
        }

        return $description;
    }
}

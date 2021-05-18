<?php
namespace Enomotodev\GitLabCIComposerUpdateMr;

use DateTime;
use Exception;
use Gitlab\Client;

class Command
{
    /**
     * Main
     *
     * @return void
     */
    public static function main()
    {
        $command = new static;

        $command->run($_SERVER['argv']);
    }

    /**
     * Run
     *
     * @param array $argv Commandline args
     *
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

        system('composer update --no-progress');

        $now    = new DateTime('now');
        $branch = 'composer-update-' . $now->format('YmdHis');

        if (strpos(
            system('git status -s composer.lock'),
            'composer.lock'
        ) === false
        ) {
            fwrite(STDOUT, 'No changes.' . PHP_EOL);
            exit(0);
        }

        $this->_setupGit($name, $email);

        $composer_home = $this->_getHomeDir();

        $json = system("{$composer_home}/vendor/bin/composer-lock-diff --json");
        $diff = json_decode($json, true);

        $description = $this->_createMergeRequestDescription($diff);

        $this->_createBranch($branch);
        $this->_createMergeRequest($base, $branch, $now, $description);
    }

    /**
     * Set up git
     *
     * @param string $name  Name
     * @param string $email Email
     *
     * @return void
     */
    private function _setupGit($name, $email)
    {
        system("git config user.name {$name}");
        system("git config user.email {$email}");

        $token         = getenv('GITLAB_API_PRIVATE_TOKEN');
        $repositoryUrl = getenv('CI_REPOSITORY_URL');
        preg_match(
            '/https:\/\/gitlab-ci-token:(.*)@(.*)/',
            $repositoryUrl,
            $matches
        );

        $origin_url = sprintf(
            'https://gitlab-ci-token:%s@%s',
            $token,
            $matches[2]
        );

        system("git remote set-url origin \"{$origin_url}\"");
    }

    /**
     * Greate git branch
     *
     * @param string $branch Branch name
     *
     * @return void
     */
    private function _createBranch($branch)
    {
        system("git checkout -b {$branch}");
        system('git add composer.lock');
        system("git commit -m '$ composer update'");
        system("git push origin {$branch}");
    }

    /**
     * Create merge request
     *
     * @param string    $base        Git base
     * @param string    $branch      Branch name
     * @param \DateTime $now         Current date
     * @param string    $description MR description
     *
     * @return void
     */
    private function _createMergeRequest($base, $branch, $now, $description)
    {
        $token         = getenv('GITLAB_API_PRIVATE_TOKEN');
        $projectId     = getenv('CI_PROJECT_ID');
        $projectPath   = getenv('CI_PROJECT_PATH');
        $repositoryUrl = getenv('CI_REPOSITORY_URL');
        $ciUrl         = str_replace("{$projectPath}.git", '', $repositoryUrl);

        $title = 'composer update at ' . $now->format('Y-m-d H:i:s T');

        $client = new Client();
        $client->setUrl($ciUrl);
        $client->authenticate($token, Client::AUTH_HTTP_TOKEN);

        try {
            $client->mergeRequests()->create(
                $projectId,
                $branch,
                $base,
                $title,
                [
                    'description'          => '## Updated Composer Packages'
                    . PHP_EOL . PHP_EOL . $description,
                    'remove_source_branch' => 1,
                ]
            );
        } catch (Exception $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }

    /**
     * Create the merge request description
     *
     * @param array $diff Diff array
     *
     * @return string
     */
    private function _createMergeRequestDescription($diff)
    {
        $description = '';
        foreach (['changes', 'changes-dev'] as $key) {
            if (!empty($diff[$key])) {
                $description .= "### {$key}" . PHP_EOL;
                foreach ($diff[$key] as $packageName => $value) {
                    if ($value[3]) {
                        $description .= "- [{$packageName}]({$value[3]}): ";
                    } else {
                        $description .= "- {$packageName}: ";
                    }
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

    /**
     * Get COMPOSER_HOME directory - uses the same logic as composer
     *
     * @return mixed
     */
    private function _getHomeDir()
    {
        $home = getenv('COMPOSER_HOME');
        if ($home) {
            return $home;
        }

        if ($this->_isWindows()) {
            if (!getenv('APPDATA')) {
                fwrite(
                    STDERR,
                    'The APPDATA or COMPOSER_HOME environment variable ' .
                    'must be set for composer to run correctly'
                    . PHP_EOL
                );
                exit(1);
            }

            return rtrim(strtr(getenv('APPDATA'), '\\', '/'), '/') . '/Composer';
        }

        $userDir = $this->_getUserDir();
        $dirs    = [];

        if ($this->_useXdg()) {
            // XDG Base Directory Specifications
            $xdgConfig = getenv('XDG_CONFIG_HOME');
            if (!$xdgConfig) {
                $xdgConfig = $userDir . '/.config';
            }

            $dirs[] = $xdgConfig . '/composer';
        }

        $dirs[] = $userDir . '/.composer';

        // select first dir which exists of: $XDG_CONFIG_HOME/composer or ~/.composer
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        // if none exists, we default to first defined one
        // (XDG one if system uses it, or ~/.composer otherwise)

        return $dirs[0];
    }

    /**
     * Get user directory
     *
     * @throws \Exception
     *
     * @return string
     */
    private function _getUserDir()
    {
        $home = getenv('HOME');
        if (!$home) {
            fwrite(
                STDERR,
                'The HOME or COMPOSER_HOME environment variable must ' .
                'be set for composer to run correctly'
                . PHP_EOL
            );
            exit(1);
        }

        return rtrim(strtr($home, '\\', '/'), '/');
    }

    /**
     * Whether the host machine is running a Windows OS
     *
     * @return bool
     */
    private function _isWindows()
    {
        return defined('PHP_WINDOWS_VERSION_BUILD');
    }

    /**
     * Return whether a shell is using useXdg
     *
     * @return bool
     */
    private function _useXdg()
    {
        foreach (array_keys($_SERVER) as $key) {
            if (strpos($key, 'XDG_') === 0) {
                return true;
            }
        }

        if (is_dir('/etc/xdg')) {
            return true;
        }

        return false;
    }
}

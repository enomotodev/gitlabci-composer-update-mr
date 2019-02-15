# gitlabci-composer-update-mr

[![Latest Stable Version](https://poser.pugx.org/enomotodev/gitlabci-composer-update-mr/v/stable.png)](https://packagist.org/packages/enomotodev/gitlabci-composer-update-mr)

## Installation

```
$ composer require enomotodev/gitlabci-composer-update-mr
```

## Prerequisites

The application on which you want to run continuous composer update must be configured to be built on GitLabCI.

## Usage

### Setting GitLab personal access token to GitLabCI

GitLab personal access token is required for sending merge requests to your repository.

1. Go to [your account's settings page](https://gitlab.com/profile/personal_access_tokens) and generate a personal access token with "api" scope
1. On GitLab dashboard, go to your application's "Settings" -> "CI /CD" -> "Environment variables"
1. Add an environment variable `GITLAB_API_PRIVATE_TOKEN` with your GitLab personal access token

### Configure .gitlab-ci.yml

Configure your `.gitlab-ci.yml` to run `gitlabci-composer-update-mr`, for example:

```yaml
job:
  except:
    - schedules
  script:
    # snip

job:on-schedule:
  image: composer:latest
  only:
    - schedules
  script:
    - "composer global require enomotodev/gitlabci-composer-update-mr"
    - "$COMPOSER_HOME/vendor/bin/gitlabci-composer-update-mr <username> <email> master"
```

NOTE: Please make sure you replace `<username>` and `<email>` with yours.

### Setting Schedule

1. On GitLab dashboard, go to your application's "Schedules" -> "New schedule"
1. Create new schedule and save

## CLI command references

General usage:

```
$ gitlabci-composer-update-mr <git username> <git email address> <git base branch>
```

## License

gitlabci-composer-update-mr is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)

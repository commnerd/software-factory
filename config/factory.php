<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Container executor
    |--------------------------------------------------------------------------
    | "docker" runs every install/test/build command inside an isolated,
    | throw-away container. "fake" simulates results (offline demos, CI).
    */
    'executor' => env('FACTORY_EXECUTOR', 'docker'),

    // Optional HTTP Basic password for the whole dashboard (any username). Strongly recommended.
    'access_password' => env('FACTORY_ACCESS_PASSWORD'),

    'container' => [
        'timeout' => (int) env('FACTORY_CONTAINER_TIMEOUT', 900),
        'memory' => env('FACTORY_CONTAINER_MEMORY', '2g'),
        'cpus' => env('FACTORY_CONTAINER_CPUS', '2'),
        // Dependency installs need network access; "none" is for pre-baked images only.
        'network' => env('FACTORY_CONTAINER_NETWORK', 'bridge'),
        // Containers run as the app's own uid:gid (so files they write stay owned by the app),
        // which means tool caches must live somewhere writable.
        'env' => [
            'HOME' => '/tmp',
            'COMPOSER_HOME' => '/tmp/composer',
            'npm_config_cache' => '/tmp/.npm',
            'GOCACHE' => '/tmp/go-cache',
            'GOPATH' => '/tmp/go',
            'CARGO_HOME' => '/tmp/cargo',
            'GEM_HOME' => '/tmp/gems',
            'BUNDLE_PATH' => '/tmp/bundle',
            'PIP_USER' => '1',
            'PYTHONUSERBASE' => '/tmp/.local',
            'PIP_CACHE_DIR' => '/tmp/.pip',
            'DOTNET_CLI_HOME' => '/tmp',
            'NUGET_PACKAGES' => '/tmp/nuget',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    | host_project_path is the location of this repository as seen by the Docker
    | daemon (under Sail the daemon lives on the host, not in the app container).
    | Sail's compose file sets it to ${PWD}. Leave null when running on the host.
    */
    'workspace_path' => env('FACTORY_WORKSPACE_PATH', storage_path('app/workspaces')),
    'host_project_path' => env('FACTORY_HOST_PROJECT_PATH'),

    /*
    |--------------------------------------------------------------------------
    | TDD + budget policy
    |--------------------------------------------------------------------------
    */
    'tdd' => [
        'max_red_attempts' => 2,
        'max_green_attempts' => (int) env('FACTORY_MAX_GREEN_ATTEMPTS', 4),
    ],

    'default_budget_usd' => (float) env('FACTORY_DEFAULT_BUDGET_USD', 25),
    'budget_raise_usd' => (float) env('FACTORY_BUDGET_RAISE_USD', 25),

    /*
    |--------------------------------------------------------------------------
    | AI providers (adapter layer)
    |--------------------------------------------------------------------------
    | Each ModelConfiguration row picks one of these drivers. "openai" speaks the
    | OpenAI chat-completions protocol, so it also serves OpenRouter, vLLM, LM Studio...
    | "ollama" is the same protocol with a local base URL and no key.
    */
    'providers' => [
        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'version' => '2023-06-01',
        ],
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],
        'ollama' => [
            'key' => null,
            'base_url' => env('OLLAMA_BASE_URL', 'http://host.docker.internal:11434/v1'),
        ],
        'fake' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Language / framework profiles
    |--------------------------------------------------------------------------
    | Each profile = container image + commands + GitHub Actions setup. Projects
    | may override any key via projects.config. Add new stacks by adding entries.
    */
    'languages' => [
        'php' => [
            'label' => 'PHP / Laravel',
            'image' => 'composer:2',
            'install' => 'composer install --no-interaction --prefer-dist',
            'test' => 'vendor/bin/phpunit --testdox',
            'test_dir' => 'tests',
            'gha_setup' => [
                ['uses' => 'shivammathur/setup-php@v2', 'with' => ['php-version' => '8.4']],
                ['run' => 'composer install --no-interaction --prefer-dist'],
            ],
            'gha_test' => 'vendor/bin/phpunit',
            'gha_build' => 'composer install --no-dev --optimize-autoloader',
        ],
        'node' => [
            'label' => 'Node.js / TypeScript',
            'image' => 'node:22',
            'install' => 'npm install --no-audit --no-fund',
            'test' => 'npm test --silent',
            'test_dir' => 'tests',
            'gha_setup' => [
                ['uses' => 'actions/setup-node@v4', 'with' => ['node-version' => '22', 'cache' => 'npm']],
                ['run' => 'npm ci'],
            ],
            'gha_test' => 'npm test',
            'gha_build' => 'npm run build --if-present',
        ],
        'python' => [
            'label' => 'Python',
            'image' => 'python:3.13',
            'install' => 'pip install -q -r requirements.txt',
            'test' => 'python -m pytest -q',
            'test_dir' => 'tests',
            'gha_setup' => [
                ['uses' => 'actions/setup-python@v5', 'with' => ['python-version' => '3.13']],
                ['run' => 'pip install -r requirements.txt'],
            ],
            'gha_test' => 'python -m pytest -q',
            'gha_build' => 'python -m compileall .',
        ],
        'go' => [
            'label' => 'Go',
            'image' => 'golang:1.24',
            'install' => 'go mod download',
            'test' => 'go test ./...',
            'test_dir' => '.',
            'gha_setup' => [
                ['uses' => 'actions/setup-go@v5', 'with' => ['go-version' => '1.24']],
            ],
            'gha_test' => 'go test ./...',
            'gha_build' => 'go build ./...',
        ],
        'java' => [
            'label' => 'Java / Maven',
            'image' => 'maven:3-eclipse-temurin-21',
            'install' => 'mvn -q -B dependency:resolve',
            'test' => 'mvn -B test',
            'test_dir' => 'src/test',
            'gha_setup' => [
                ['uses' => 'actions/setup-java@v4', 'with' => ['distribution' => 'temurin', 'java-version' => '21', 'cache' => 'maven']],
            ],
            'gha_test' => 'mvn -B test',
            'gha_build' => 'mvn -B -DskipTests package',
        ],
        'dotnet' => [
            'label' => 'C# / .NET',
            'image' => 'mcr.microsoft.com/dotnet/sdk:9.0',
            'install' => 'dotnet restore',
            'test' => 'dotnet test --nologo',
            'test_dir' => 'tests',
            'gha_setup' => [
                ['uses' => 'actions/setup-dotnet@v4', 'with' => ['dotnet-version' => '9.0.x']],
                ['run' => 'dotnet restore'],
            ],
            'gha_test' => 'dotnet test --no-restore',
            'gha_build' => 'dotnet build --no-restore -c Release',
        ],
        'ruby' => [
            'label' => 'Ruby / Rails',
            'image' => 'ruby:3.4',
            'install' => 'bundle install --quiet',
            'test' => 'bundle exec rspec',
            'test_dir' => 'spec',
            'gha_setup' => [
                ['uses' => 'ruby/setup-ruby@v1', 'with' => ['ruby-version' => '3.4', 'bundler-cache' => true]],
            ],
            'gha_test' => 'bundle exec rspec',
            'gha_build' => 'bundle exec rake assets:precompile',
        ],
        'rust' => [
            'label' => 'Rust',
            'image' => 'rust:1',
            'install' => 'cargo fetch',
            'test' => 'cargo test',
            'test_dir' => 'tests',
            'gha_setup' => [
                ['uses' => 'dtolnay/rust-toolchain@stable'],
            ],
            'gha_test' => 'cargo test',
            'gha_build' => 'cargo build --release',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-iteration (the factory improving itself)
    |--------------------------------------------------------------------------
    */
    'self' => [
        'image' => env('FACTORY_SELF_IMAGE', 'sail-8.5/app'),
        'test_command' => 'php artisan test',
        'staging_path' => storage_path('app/staging'),
        'staged_path' => storage_path('app/staged'),
        // Only these locations may be modified by a proposal.
        'allowed_paths' => ['app', 'config', 'database', 'resources', 'routes', 'tests', 'README.md'],
        'denied_patterns' => ['.env', 'vendor/', 'node_modules/', '.git/', 'storage/'],
    ],
];

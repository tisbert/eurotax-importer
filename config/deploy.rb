# config valid only for current version of Capistrano

set :application, 'eurotax-importer'
set :repo_url, 'git@github.com:vpauto/eurotax-importer.git'

set :user, "appventus"

# Default value for :pty is false
set :pty, true

# Default value for :linked_files is []
set :linked_files, [
    "app/config/parameters.yml",
]

set :file_permissions_users, ['www-data', 'vpauto']
set :webserver_user,        "www-data"

set :ssh_options, {
    keys: %w(~/.ssh/id_rsa),
    forward_agent: false
}


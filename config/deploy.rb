# config valid only for current version of Capistrano
lock '3.4.0'

set :application, 'eurotax-importer'
set :repo_url, 'git@github.com:vpauto/eurotax-importer.git'

set :user, "appventus"

server 'vpauto.fr',
    user: fetch(:user),
    port: 22,
    roles: %w{web app db}



# Default value for :pty is false
set :pty, true

# Default value for :linked_files is []
set :linked_files, [
    fetch(:app_path) + "/config/parameters.yml",
]

set :file_permissions_users, ['www-data', 'vpauto']
set :webserver_user,        "www-data"

# Default value for linked_dirs is []
set :linked_dirs,
[
    fetch(:log_path)
]
set :ssh_options, {
    keys: %w(~/.ssh/id_rsa),
    forward_agent: false
}

namespace :deploy do

  after :restart, :clear_cache do
    on roles(:web), in: :groups, limit: 3, wait: 10 do
      # Here we can do anything such as:
      # within release_path do
      #   execute :rake, 'cache:clear'
      # end
    end
  end

end

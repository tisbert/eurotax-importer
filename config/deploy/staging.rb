set :stage, :staging
set :server, '62.210.254.86'

server fetch(:server),
    user: fetch(:user),
    port: 22,
    roles: %w{web app db}

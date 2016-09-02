set :stage, :production
set :server, 'vpauto.fr'

server fetch(:server),
    user: fetch(:user),
    port: 22,
    roles: %w{web app db}

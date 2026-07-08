# MariaDB for OpenEMR on Railway.
#
# Railway's private network (<service>.railway.internal) is IPv6-only, but the
# stock mariadb image defaults to bind-address = 0.0.0.0 (IPv4 only), so the
# openemr service could not reach it. Override CMD to bind :: (all interfaces,
# incl. IPv6) and default the server charset to utf8mb4 to match
# docker/production/docker-compose.yml.
#
# Deployed as its own Railway service via:
#   railway up deploy/railway/mariadb --service mariadb --detach
# Digest-pinned so rebuilds are reproducible (mariadb:11.8 as of 2026-07-08);
# bump deliberately by re-resolving: docker buildx imagetools inspect mariadb:11.8
FROM mariadb:11.8@sha256:efb4959ef2c835cd735dbc388eb9ad6aab0c78dd64febcd51bc17481111890c4
CMD ["mariadbd", "--bind-address=::", "--character-set-server=utf8mb4"]

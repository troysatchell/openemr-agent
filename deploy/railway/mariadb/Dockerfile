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
FROM mariadb:11.8
CMD ["mariadbd", "--bind-address=::", "--character-set-server=utf8mb4"]

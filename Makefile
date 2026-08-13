.PHONY: help up down bootstrap logs wp reset shell open urls static preview

PORT ?= 8888

help:
	@grep -E '^[a-z-]+:.*?##' $(MAKEFILE_LIST) | sed 's/:.*##/\t/' | column -t -s "$$(printf '\t')"

up:            ## Start WordPress + MariaDB + Adminer
	docker compose --profile tools up -d
	@echo "→ http://localhost:$(PORT)/"

down:          ## Stop everything (keeps data)
	docker compose --profile tools down

bootstrap:     ## Install WordPress and (re)create the lab pages
	./scripts/bootstrap.sh

logs:          ## Tail WordPress + PHP logs
	docker compose logs -f wordpress

wp:            ## Run WP-CLI, e.g. make wp CMD="plugin list"
	docker compose run --rm -T wpcli $(CMD)

debuglog:      ## Tail wp-content/debug.log
	docker compose exec wordpress tail -f /var/www/html/wp-content/debug.log

reset:         ## DESTROY the database and volumes, then rebuild from scratch
	docker compose --profile tools down -v
	docker compose --profile tools up -d
	./scripts/bootstrap.sh

static:        ## Export the running site to docs/ for GitHub Pages
	./scripts/export-static.sh

preview:       ## Serve docs/ at http://localhost:8090 to check the static build
	@echo "→ http://localhost:8090/"
	python3 -m http.server -d docs 8090

urls:          ## Print every lab URL
	@echo "http://localhost:$(PORT)/"
	@for p in lab-clicks lab-hover lab-scroll lab-forms lab-spa lab-ecommerce lab-edge; do \
		echo "http://localhost:$(PORT)/$$p/"; done

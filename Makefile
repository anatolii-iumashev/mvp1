SHELL := /bin/bash

PHP := php
COMPOSER := composer
NPM := npm

.DEFAULT_GOAL := help

.PHONY: help setup dev serve queue logs vite build test pint migrate fresh seed tinker clear

help:
	@echo "Available targets:"
	@echo "  make setup    - Install dependencies, prepare .env, migrate, and build assets"
	@echo "  make dev      - Run the full local dev stack"
	@echo "  make serve    - Start the Laravel HTTP server"
	@echo "  make queue    - Start the queue worker"
	@echo "  make logs     - Stream application logs with pail"
	@echo "  make vite     - Start the Vite dev server"
	@echo "  make build    - Build frontend assets for production"
	@echo "  make test     - Run the test suite"
	@echo "  make pint     - Format PHP code with Pint"
	@echo "  make migrate  - Run database migrations"
	@echo "  make fresh    - Refresh the database"
	@echo "  make seed     - Seed the database"
	@echo "  make tinker   - Open the Laravel Tinker shell"
	@echo "  make clear    - Clear Laravel caches"

setup:
	$(COMPOSER) install
	@if [ ! -f .env ]; then cp .env.example .env; fi
	$(PHP) artisan key:generate
	$(PHP) artisan migrate --force
	$(NPM) install --ignore-scripts
	$(NPM) run build

dev:
	$(COMPOSER) run dev

serve:
	$(PHP) artisan serve

queue:
	$(PHP) artisan queue:listen --tries=1 --timeout=0

logs:
	$(PHP) artisan pail --timeout=0

vite:
	$(NPM) run dev

build:
	$(NPM) run build

test:
	$(PHP) artisan test

pint:
	vendor/bin/pint

migrate:
	$(PHP) artisan migrate

fresh:
	$(PHP) artisan migrate:fresh

seed:
	$(PHP) artisan db:seed

tinker:
	$(PHP) artisan tinker

clear:
	$(PHP) artisan optimize:clear

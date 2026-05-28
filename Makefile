SHELL := /bin/bash
DOCKER_COMPOSE ?= docker compose
HOST_UID ?= $(shell id -u)
HOST_GID ?= $(shell id -g)
RUN_COMMAND ?= ${DOCKER_COMPOSE} run --rm application
QUALITY_RUN_COMMAND ?= ${DOCKER_COMPOSE} --env-file /dev/null run --rm -e JIRA_BASE_URL= -e JIRA_EMAIL= -e JIRA_API_TOKEN= -e JIRA_JQL= -e JIRA_TIMEZONE=Europe/Warsaw -e JIRA_FROM= -e JIRA_TO= -e JIRA_OUTPUT= -v /dev/null:/app/.env:ro application
EXEC_COMMAND ?= ${RUN_COMMAND}
QUALITY_EXEC_COMMAND ?= ${QUALITY_RUN_COMMAND}
COMPOSER_EXEC ?= composer
PHPUNIT_TEST_PATH ?= tests
PHPSTAN_CONFIG ?= phpstan.neon
PHPSTAN_MEMORY_LIMIT ?= 1G
CS_FIXER_CONFIG ?= .php-cs-fixer.php
INPUT ?= raports/jira_raport_20260521.csv
OUTPUT ?= raports/grouped.csv
FROM ?=
TO ?=
JQL ?=
ENV_FILE ?=
CMD ?= bash
JQL_ARG = $(if $(JQL),--jql "$(JQL)",)
ENV_ARG = $(if $(ENV_FILE),--env ${ENV_FILE},)
FROM_ARG = $(if $(FROM),--from ${FROM},)
TO_ARG = $(if $(TO),--to ${TO},)
OUTPUT_ARG = $(if $(OUTPUT),--output ${OUTPUT},)

export HOST_UID
export HOST_GID

.PHONY: help build up down composer_install test stan cs cs_fix check bash run report report_api

help: # Show help for each Makefile recipe.
	@grep -E '^[a-zA-Z0-9_-]+:.*#' Makefile | sort | while read -r l; do printf "\033[1;32m$$(echo $$l | cut -f 1 -d':')\033[00m:$$(echo $$l | cut -f 2- -d'#')\n"; done

build: # Build docker compose images.
	${DOCKER_COMPOSE} build

up: # Start docker compose services in the background.
	${DOCKER_COMPOSE} up -d --remove-orphans

down: # Stop docker compose services.
	${DOCKER_COMPOSE} down --remove-orphans

composer_install: # Install Composer dependencies in the application container.
	${EXEC_COMMAND} ${COMPOSER_EXEC} install

test: # Run PHPUnit tests in the application container.
	${QUALITY_EXEC_COMMAND} vendor/bin/phpunit --configuration phpunit.xml ${PHPUNIT_TEST_PATH}

stan: # Run PHPStan static analysis at the configured level.
	${QUALITY_EXEC_COMMAND} vendor/bin/phpstan analyse --configuration ${PHPSTAN_CONFIG} --memory-limit ${PHPSTAN_MEMORY_LIMIT}

cs: # Check coding style with PHP-CS-Fixer without modifying files.
	${QUALITY_EXEC_COMMAND} vendor/bin/php-cs-fixer fix --config=${CS_FIXER_CONFIG} --dry-run --diff

cs_fix: # Fix coding style with PHP-CS-Fixer.
	${EXEC_COMMAND} vendor/bin/php-cs-fixer fix --config=${CS_FIXER_CONFIG}

check: test stan cs # Run the full local quality gate.

bash: # Run an interactive command in the application container; override with CMD='php -v'.
	${EXEC_COMMAND} ${CMD}

run: # Run an arbitrary command in the application container, e.g. CMD='php -v'.
	${EXEC_COMMAND} ${CMD}

report: # Generate Jira grouped report; override INPUT and OUTPUT.
	${EXEC_COMMAND} php bin/jira-timesheet ${INPUT} ${OUTPUT}

report_api: # Generate Jira grouped report from Jira API config; override FROM, TO, OUTPUT, ENV_FILE, optional JQL.
	${EXEC_COMMAND} php bin/jira-timesheet api ${ENV_ARG} ${FROM_ARG} ${TO_ARG} ${OUTPUT_ARG} ${JQL_ARG}

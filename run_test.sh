#!/bin/bash

docker compose -f docker-compose.test.yaml run --rm esn_test
docker compose -f docker-compose.test.yaml stop

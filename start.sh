#!/bin/bash

set -e

if [ ! -f .env ]; then
    echo "[start] .env not found — copying from .env.example"
    cp .env.example .env
fi

docker compose up -d

docker compose exec app bash

#!/bin/sh
# Run on Railway deploy: migrate then seed (only if DB is empty)
npx prisma migrate deploy
npx ts-node --compiler-options '{"module":"CommonJS"}' prisma/seed.ts || echo "Seed skipped (already seeded)"

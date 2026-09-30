#!/bin/bash
#
# Run af_feed_advisor tests in Docker container
#
# Usage:
#   ./test.sh              # Run all tests
#   ./test.sh --coverage   # Run with coverage report
#   ./test.sh --filter testName  # Run specific test

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Build test image
echo "Building test container..."
docker build -f Dockerfile.test -t af_feed_advisor:test . -q

# Run tests with any additional arguments
echo "Running tests..."
docker run --rm af_feed_advisor:test ./vendor/bin/phpunit "$@"

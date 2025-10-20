# Testing Guide

## Running Tests

### Quick test run

Pre-requisite: 
 - docker is installed
 - Java 21 is installed
 - mvn is available

```bash
# Using the test script (recommended)
./run_test.sh

# Or manually with docker-compose
docker compose -f docker-compose.test.yaml run --rm esn_test
```

### Using Makefile

```bash
# Run all tests
make test

# Run specific test file
make test TARGET=tests/CalDAV/Backend/MongoTest.php

# Run with code linting
make check

# Perform only linting
make lint
```



## Test Coverage

### Important Note on Coverage Metrics

⚠️ **The coverage report generated here shows only direct tests within this repository.**

This project (esn-sabre) is also tested by:
- **[twake-calendar-side-service](https://github.com/linagora/twake-calendar-side-service)** - Integration tests that exercise esn-sabre's CalDAV/CardDAV functionality
- **OpenPaaS applications** - End-to-end tests that interact with this backend

The reported coverage (e.g., 8.10%) represents **unit test coverage only** and does not reflect:
- Integration tests from dependent projects
- End-to-end tests in the full stack
- Actual production usage validation

**True coverage is significantly higher** when including these external test suites.

### Prerequisites

The coverage report requires Xdebug, which is not included in the standard Docker image to keep it lightweight.

### Generate Coverage Report

```bash
# Use the coverage script
./generate-coverage.sh
```

This will:
1. Build a Docker image with Xdebug (`esn_sabre_coverage`)
2. Start required services (MongoDB, RabbitMQ, LDAP)
3. Run all tests with coverage enabled
4. Generate an HTML report in `tests/report/`

### View Coverage Report

```bash
# Open in your browser
firefox tests/report/index.html

# Or
xdg-open tests/report/index.html
```

The report shows:
- Line coverage for all files in `lib/`
- Which lines are covered by tests
- Coverage percentage per file/directory
- Total project coverage

### Manual Coverage Generation

If you need more control:

```bash
# Build coverage image
docker build -f Dockerfile.coverage -t esn_sabre_coverage .

# Start services
docker compose -f docker-compose.test.yaml up -d

# Run with coverage
docker run --rm \
  --network esn-sabre_esn-sabre-test-net \
  -v "$(pwd)/tests/report:/var/www/tests/report" \
  esn_sabre_coverage \
  bash -c "cd /var/www && make test-report"
```

## Test Organization

### Directory Structure

```
tests/
├── CalDAV/
│   ├── Backend/          # Backend implementation tests
│   │   ├── MongoTest.php
│   │   ├── EsnTest.php
│   │   └── RecurrenceExpansionTest.php
│   └── Schedule/         # Scheduling & notifications
│       ├── IMipPluginTest.php
│       └── IMipPluginRecurrentEventTest.php
├── CardDAV/             # CardDAV tests
├── DAV/                 # Core DAV tests
├── DAVACL/              # ACL tests
├── JSON/                # JSON API tests
└── phpunit.xml          # PHPUnit configuration
```

### Test Categories

- **Unit tests**: Test individual components in isolation
- **Integration tests**: Test component interactions (MongoDB, LDAP, etc.)
- **Recurrence tests**: Test recurring event expansion and timezone handling

## Writing Tests

### Creating New Tests

1. Extend the appropriate base class:
   ```php
   class MyTest extends \PHPUnit_Framework_TestCase {
       // ...
   }
   ```

2. For backend tests, extend `AbstractDatabaseTest`:
   ```php
   class MyBackendTest extends AbstractDatabaseTest {
       protected function getBackend() {
           return new MyBackend($this->db);
       }

       protected function generateId() {
           return [(string) new \MongoDB\BSON\ObjectId(),
                   (string) new \MongoDB\BSON\ObjectId()];
       }
   }
   ```

3. Use descriptive test names:
   ```php
   function testDailyRecurringEventExpansion() {
       // Test implementation
   }
   ```

### Best Practices

- **Document complex scenarios** with comments explaining RRULE syntax, DST behavior, etc.
- **Use meaningful assertions** with descriptive failure messages
- **Clean up resources** in tearDown() methods
- **Test edge cases** especially for timezone and recurrence logic
- **Keep tests focused** on a single behavior

## Continuous Integration

Tests run automatically on:
- Pull requests
- Commits to master
CI configuration: `.gitlab-ci.yml`

## Troubleshooting

### "No code coverage driver is available"

The standard Docker image doesn't include Xdebug. Use `./generate-coverage.sh` or `Dockerfile.coverage`.

### MongoDB connection errors

Ensure services are running:
```bash
docker compose -f docker-compose.test.yaml ps
```

### LDAP bind errors

Check that `esn_ldap` container is healthy:
```bash
docker compose -f docker-compose.test.yaml logs esn_ldap
```

### Memory errors

Increase Docker memory limit or reduce test parallelism.

## Related Documentation

- [PHPUnit Documentation](https://phpunit.de/documentation.html)

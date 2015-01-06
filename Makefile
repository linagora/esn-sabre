BASEDIR=$(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
PHPCS=$(BASEDIR)/vendor/bin/phpcs
PHPUNIT=$(BASEDIR)/vendor/bin/phpunit

PHPUNIT_CONFIG=$(BASEDIR)/tests/phpunit.xml
PHPUNIT_REPORT=$(BASEDIR)/tests/report/
PHPCS_CONFIG=$(BASEDIR)/tests/phpcs.xml

TARGET ?= $(BASEDIR)

check: lint test-report

$(PHPCS) $(PHPUNIT):
	composer install --working-dir $(BASEDIR)

test: $(PHPUNIT)
	$(PHPUNIT) -c $(PHPUNIT_CONFIG) $(TARGET)

test-report:
	$(PHPUNIT) -c $(PHPUNIT_CONFIG) --coverage-html $(PHPUNIT_REPORT) $(TARGET)
	@echo Check out file://$(abspath $(BASEDIR)/tests/report/index.html)

lint: $(PHPCS)
	$(PHPCS) -p --standard=$(PHPCS_CONFIG) $(TARGET)


update: $(PHPCS) $(PHPUNIT)

.PHONY: update test test-report lint check

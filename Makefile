BASEDIR=$(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
PHPCS=$(BASEDIR)/vendor/bin/phpcs
PHPUNIT=$(BASEDIR)/vendor/bin/phpunit

PHPUNIT_CONFIG=$(BASEDIR)/tests/phpunit.xml
PHPUNIT_REPORT=$(BASEDIR)/tests/report/
PHPCS_CONFIG=$(BASEDIR)/tests/phpcs.xml

ifeq ($(origin TARGET), undefined)
UNIT_TARGET=$(BASEDIR)/tests
LINT_TARGET=$(BASEDIR)
else
UNIT_TARGET=$(TARGET)
LINT_TARGET=$(TARGET)
endif

check: lint test-report

$(PHPCS) $(PHPUNIT):
	composer install --working-dir $(BASEDIR)

test: $(PHPUNIT)
	$(PHPUNIT) -c $(PHPUNIT_CONFIG) --display-deprecations $(UNIT_TARGET) || (test $$? -eq 1 && echo "Tests passed with warnings/deprecations")

test-report: $(PHPUNIT)
	$(PHPUNIT) -c $(PHPUNIT_CONFIG) --coverage-html $(PHPUNIT_REPORT) $(UNIT_TARGET)
	@echo Check out file://$(abspath $(BASEDIR)/tests/report/index.html)

lint: $(PHPCS)
	$(PHPCS) -p --standard=$(PHPCS_CONFIG) $(LINT_TARGET)

update: $(PHPCS) $(PHPUNIT)

.PHONY: update test test-report lint check

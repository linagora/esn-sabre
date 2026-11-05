"""Summarize PHPUnit and Surefire failures for the Jenkins PR comment."""

from html import escape
from pathlib import Path
import xml.etree.ElementTree as ET


def excerpt(text, limit):
    return escape(" ".join(text.split())[:limit], quote=False).replace("`", "'")


reports = [('PHP', path) for path in sorted(Path('tests/report/php').glob('*.xml'))]
# run_test.sh copies the two Java groups here before removing the it-tests clone.
reports += [('Java integration', path) for path in sorted(
    Path('tests/report/java').glob('*/target/surefire-reports/TEST-*.xml'))]
failures = []
failure_count = 0
unreadable = 0
for source, report in reports:
    try:
        root = ET.parse(report).getroot()
    except (ET.ParseError, OSError):
        unreadable += 1
        continue
    for test in root.iter('testcase'):
        failure = next((child for child in test if child.tag in {'failure', 'error'}), None)
        if failure is None:
            continue
        failure_count += 1
        if len(failures) == 10:
            continue
        name = excerpt(f"{test.get('classname', '')}#{test.get('name', '')}", 300)
        lines = (failure.text or '').strip().splitlines()
        # PHPUnit repeats Class::method before the assertion; Surefire uses message.
        if lines and lines[0] == f"{test.get('classname', '')}::{test.get('name', '')}":
            lines = lines[1:]
        message = failure.get('message') or next((line for line in lines if line.strip()), '')
        failures.append((source, name, excerpt(message, 300)))

if failures:
    print(f'**Recorded test failures ({failure_count}):**\n')
    # Bound the comment size; full failure details remain in Jenkins.
    for source, name, message in failures:
        print(f'> **{source}** — `{name}`')
        if message:
            print(f'> `{message}`')
        print()
    if failure_count > 10:
        print(f'Showing the first 10; {failure_count - 10} more in the build report.\n')
elif reports and not unreadable:
    print('> No failed test cases were found in the available reports. See the build log for lint, build or infrastructure failures.')
elif not reports:
    print('> No test reports were available. See the build log for lint, build or infrastructure failures.')

if unreadable:
    print(f'> Could not read {unreadable} test report(s). See the build log.')

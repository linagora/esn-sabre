"""Run with python3 -m unittest discover -s ci -p 'test_*.py'."""

from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


SCRIPT = Path(__file__).with_name('summarize-test-failures.py')


class SummarizeTestFailuresTest(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory()
        self.addCleanup(self.workspace.cleanup)
        self.root = Path(self.workspace.name)

    def write(self, name, content):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)

    def summary(self):
        return subprocess.run([sys.executable, str(SCRIPT)], cwd=self.root,
                              check=True, capture_output=True, text=True).stdout

    def test_phpunit_and_both_java_groups_survive_removed_clone(self):
        self.write('tests/report/php/junit.xml', '''<testsuites><testsuite><testsuite>
            <testcase classname="ESN\\CalDAV\\PluginTest" name="testReply">
                <failure type="AssertionFailedError">ESN\\CalDAV\\PluginTest::testReply
                Expected 204, got 500
                Stack trace that should not appear</failure>
            </testcase><testcase name="passing"/><testcase name="skipped"><skipped/></testcase>
            </testsuite></testsuite></testsuites>''')
        for group in ['cal', 'other']:
            self.write(f'tests/report/java/{group}/target/surefire-reports/TEST-dav.xml',
                       f'''<testsuite><testcase classname="DavTest" name="{group}">
                       <error message="Service unavailable">Full stack trace</error>
                       </testcase></testsuite>''')
        summary = self.summary()
        self.assertIn('Recorded test failures (3)', summary)
        self.assertIn('**PHP** — `ESN\\CalDAV\\PluginTest#testReply`', summary)
        self.assertIn('Expected 204, got 500', summary)
        self.assertNotIn('::testReply', summary)
        self.assertIn('**Java integration** — `DavTest#cal`', summary)
        self.assertIn('**Java integration** — `DavTest#other`', summary)
        self.assertNotIn('Stack trace', summary)
        self.assertNotIn('#passing', summary)
        self.assertNotIn('#skipped', summary)

    def test_limits_failure_count_and_excerpt_size(self):
        self.write('tests/report/php/junit.xml', '<testsuite>' + ''.join(
            f'<testcase name="test{i}"><failure>{"x" * 1000}</failure></testcase>'
            for i in range(12)) + '</testsuite>')
        summary = self.summary()
        self.assertIn('Recorded test failures (12)', summary)
        self.assertEqual(10, summary.count('**PHP**'))
        self.assertIn('2 more in the build report', summary)
        self.assertNotIn('x' * 301, summary)

    def test_missing_reports_explain_non_test_failures(self):
        self.assertIn('No test reports were available', self.summary())

    def test_passing_reports_do_not_imply_successful_pipeline(self):
        self.write('tests/report/php/junit.xml', '<testsuite><testcase name="passing"/></testsuite>')
        self.assertIn('lint, build or infrastructure failures', self.summary())

    def test_malformed_report_does_not_hide_valid_failure(self):
        self.write('tests/report/php/broken.xml', '<testsuite')
        self.write('tests/report/java/cal/target/surefire-reports/TEST-dav.xml',
                   '<testsuite><testcase name="testGet"><failure/></testcase></testsuite>')
        self.assertIn('#testGet', self.summary())
        self.assertIn('Could not read 1 test report(s)', self.summary())

    def test_unrelated_reports_are_not_counted_and_markup_is_escaped(self):
        self.write('it-tests/target/surefire-reports/TEST-old.xml',
                   '<testsuite><testcase name="old"><failure/></testcase></testsuite>')
        self.write('tests/report/php/junit.xml', '''<testsuite><testcase name="test`&lt;tag&gt;">
            <failure message="&lt;script&gt; `quoted`"/></testcase></testsuite>''')
        summary = self.summary()
        self.assertIn("test'&lt;tag&gt;", summary)
        self.assertIn("&lt;script&gt; 'quoted'", summary)
        self.assertNotIn('#old', summary)

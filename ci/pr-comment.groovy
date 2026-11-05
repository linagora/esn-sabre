import groovy.transform.Field
import org.jenkinsci.plugins.workflow.steps.FlowInterruptedException

@Field String failedTestSummary = ''

// Keep only Markdown so the result can be posted after the workspace is deleted.
def collectFailures() {
    if (currentBuild.currentResult != 'SUCCESS') {
        // Reports must be read before deleteDir; the Java clone is already removed.
        try {
            failedTestSummary = sh(label: 'Summarize failed tests', returnStdout: true,
                script: 'python3 ci/summarize-test-failures.py').trim()
        } catch (FlowInterruptedException interrupted) {
            throw interrupted
        } catch (Exception ignored) {
            failedTestSummary = '> Failed test details are unavailable. See the build log.'
            echo 'WARNING: Could not summarize failed tests.'
        }
    }
}

def postResult() {
    def publishedPrImage = env.PR_DOCKER_IMAGE
    try {
        def result = currentBuild.currentResult
        def icons = [SUCCESS: '✅', FAILURE: '❌', UNSTABLE: '⚠️', ABORTED: '⏹️', NOT_BUILT: '⏭️']
        def body = "${icons[result] ?: 'ℹ️'} Jenkins pipeline finished: **${result}**."
        if (failedTestSummary) {
            body += "\n\n${failedTestSummary}"
        }
        if (publishedPrImage) {
            body += "\n\n✅ Docker image published for this PR: `${publishedPrImage}`"
        }
        withEnv(["PR_COMMENT_JSON=${groovy.json.JsonOutput.toJson([body: body])}"]) {
            withCredentials([string(credentialsId: 'github-calendar-bot', variable: 'GITHUB_TOKEN')]) {
                def status = sh(label: 'Comment Jenkins result on PR', returnStatus: true, script: '''
                    set +x
                    printf '%s' "$PR_COMMENT_JSON" | curl --fail --silent --show-error \\
                        --connect-timeout 10 --max-time 30 -o /dev/null -X POST \\
                        -H "Authorization: Bearer $GITHUB_TOKEN" \\
                        -H "Content-Type: application/json" \\
                        --data-binary @- \\
                        "https://api.github.com/repos/linagora/esn-sabre/issues/$CHANGE_ID/comments"
                ''')
                if (status != 0) {
                    echo "WARNING: Could not post Jenkins result to PR (curl exit ${status})."
                }
            }
        }
    } catch (FlowInterruptedException interrupted) {
        throw interrupted
    } catch (Exception ignored) {
        echo 'WARNING: Could not post Jenkins result to PR.'
    }
}

return this

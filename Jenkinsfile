import org.jenkinsci.plugins.workflow.steps.FlowInterruptedException

def failedTestSummary = ''

pipeline {
    agent any

    /* No pipeline-wide credentials: the test stages run run_test.sh, the
       Dockerfiles and the tests from the checkout, which for a pull request
       come from its author. Credentials are bound with withCredentials only
       around the steps that need them, none of which runs code of the
       checkout. See withGithubCredential and withDockerHubLogin. */

    options {
        timeout(time: 2, unit: 'HOURS')
        disableConcurrentBuilds()
    }

    stages {
      stage('Authorize fork') {
        // A fork pull request runs no code of its own before an org member vouches for its head
        // commit, see authorizeFork
        when {
          changeRequest()
          expression { env.CHANGE_FORK != null }
        }
        steps {
          script {
            authorizeFork()
          }
        }
      }
      stage('Build Test Images') {
          steps {
              script {
                  /* Builds of other branches share this Docker daemon: tag the test
                     images per build, so that they neither replace nor remove ours. */
                  env.TEST_IMAGE_TAG = "${env.BRANCH_NAME}-${env.BUILD_NUMBER}".replaceAll('[^A-Za-z0-9_.-]', '-')
              }
              sh 'bash run_test.sh --skip-java --skip-php --skip-nginx'
          }
      }
      stage('Compile and Test') {
          steps {
              sh 'bash run_test.sh --skip-java --skip-nginx --skip-build'
          }
          post {
              always {
                  archiveArtifacts artifacts: 'tests/report/php/*.xml', allowEmptyArchive: true
                  junit testResults: 'tests/report/php/*.xml', allowEmptyResults: true
              }
          }
       }
       stage('Nginx Rate Limit Test') {
          steps {
              sh 'bash run_test.sh --skip-java --skip-php --skip-build'
          }
       }
       stage('Run Integration Test') {
          steps {
              sh 'bash run_test.sh --skip-php --skip-nginx --skip-build'
          }
          post {
              always {
                  archiveArtifacts artifacts: 'tests/report/java/test-resources.txt,tests/report/java/*/target/test-run.log,tests/report/java/*/target/surefire-reports/*', allowEmptyArchive: true
                  junit testResults: 'tests/report/java/*/target/surefire-reports/*.xml', allowEmptyResults: true
              }
          }
      }
      stage('Deliver Docker images for PR') {
        when {
          changeRequest()
        }
        steps {
          script {
            // A fork pull request reaches this stage only once authorized, see authorizeFork
            sh 'docker build --pull --no-cache -t linagora/esn-sabre-pr:$CHANGE_ID .'
            withDockerHubLogin {
              sh 'docker push linagora/esn-sabre-pr:$CHANGE_ID'
            }
            env.PR_DOCKER_IMAGE = "linagora/esn-sabre-pr:${env.CHANGE_ID}"
          }
        }
      }
      stage('Deliver Docker images') {
        when {
          anyOf {
            branch 'master'
            buildingTag()
          }
        }
        steps {
          script {
            env.DOCKER_TAG = 'branch-master'
            if (env.TAG_NAME) {
              env.DOCKER_TAG = env.TAG_NAME
            }

            echo "Docker tag: ${env.DOCKER_TAG}"
            sh 'docker build --pull --no-cache -t linagora/esn-sabre:$DOCKER_TAG .'
            withDockerHubLogin {
              sh 'docker push linagora/esn-sabre:$DOCKER_TAG'
            }
          }
        }
      }
    }

    post {
        always {
            script {
                /* Reports must be read before deleteDir. The summary script is code of the
                   checkout: it runs with no credential bound, only for a build authorized to run
                   that code, and its output is posted as data (JSON body, printf '%s'). */
                if (env.CHANGE_ID && currentBuild.currentResult != 'SUCCESS' && mayRunChangeCode()) {
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
            /* Drop the test images and the layers they pinned, on success as well
               as on failure: a surviving esn_sabre_test would be reused by the
               next build's --skip-build stages instead of the one it just built. */
            sh 'docker image rm -f "esn_sabre_test:$TEST_IMAGE_TAG" "esn-sabre-ldap-test:$TEST_IMAGE_TAG" "esn_sabre_nginx_test:$TEST_IMAGE_TAG" || true'
            sh 'docker image prune -f || true'
            deleteDir() /* clean up our workspace */
        }
        cleanup {
            script {
                if (env.CHANGE_ID) {
                    // Pipeline code of this trusted Jenkinsfile only: never load Groovy from the
                    // checkout, a fork pull request would run its own copy with the token bound.
                    postResult(failedTestSummary)
                }
            }
        }
    }
}

// Comments the result of the build on the pull request, as the calendar bot
def postResult(String failedTestSummary) {
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

def githubRepository() {
  return 'linagora/esn-sabre'
}

// ---- Fork authorization and credentials. The same block is in the Jenkinsfiles of
// twake-contacts-frontend, twake-calendar-frontend, twake-calendar-side-service, webadmin-proxy,
// tmail-flutter, esn-sabre and tmail-backend: change it in all of them. Only githubRepository()
// differs.
//
// No credential is bound at pipeline level: every stage runs code of the checked out sources (build
// plugins, package scripts, tests, scripts/), so secrets are bound only around the steps that need
// them, see withGithubCredential and withDockerHubLogin.

// A fork pull request runs no code of its own before an org member vouches for its head commit: the
// owner of the fork is a linagora member, or a member submitted a review on that very commit (an
// approval, or a review comment 'Build this please'). GitHub ties the review to that commit
// (commit_id), so a commit pushed after the approval is not built until a member reviews it again.
def authorizeFork() {
  withGithubCredential {
    // CHANGE_FORK is 'owner', or 'owner/repository' for a fork renamed on GitHub
    def forkOwner = env.CHANGE_FORK.tokenize('/')[0]
    if (isOrgMember(forkOwner)) {
      echo "Fork owner '${forkOwner}' is a linagora org member, proceeding."
      env.FORK_AUTHORIZED = 'true'
      return
    }
    def headSha = checkedOutPullRequestHead()
    def approver = memberReviewerOf(headSha)
    if (approver) {
      echo "Commit ${headSha} approved by linagora member '${approver}', proceeding."
      env.FORK_AUTHORIZED = 'true'
      return
    }
    error("Fork owner '${forkOwner}' is not a linagora org member and no member reviewed commit ${headSha}. " +
      "A linagora member must approve the pull request, or submit a review 'Build this please', " +
      "on this commit, then re-run the build.")
  }
}

// Whether the build may run code of the checked out sources, for the post actions that run even
// when the fork authorization failed
def mayRunChangeCode() {
  return env.CHANGE_FORK == null || env.FORK_AUTHORIZED == 'true'
}

// The head commit of the pull request, checked against the sources Jenkins checked out: HEAD itself,
// or the second parent of the merge commit Jenkins builds with the target branch
def checkedOutPullRequestHead() {
  def headSha = githubApi("pulls/${env.CHANGE_ID}").head.sha
  def checkedOut = sh(script: 'git rev-parse HEAD; git rev-parse -q --verify "HEAD^2" || true', returnStdout: true)
    .trim().tokenize('\n')
  if (!checkedOut.contains(headSha)) {
    error("The pull request head moved to ${headSha} since the checkout of ${checkedOut}: re-run the build.")
  }
  return headSha
}

// The login of a linagora member who approved the given commit, or reviewed it with 'Build this please'.
// GitHub lists the reviews oldest first, 100 per page at most: every page is read.
def memberReviewerOf(String sha) {
  def page = 1
  while (true) {
    def reviews = githubApi("pulls/${env.CHANGE_ID}/reviews?per_page=100&page=${page}")
    for (review in reviews) {
      def approves = review.state == 'APPROVED' ||
        (review.state != 'DISMISSED' && "${review.body}".trim() == 'Build this please')
      if (review.commit_id == sha && approves && isOrgMember(review.user.login)) {
        return review.user.login
      }
    }
    if (reviews.size() < 100) {
      return null
    }
    page++
  }
}

def isOrgMember(String login) {
  def status
  withEnv(["GITHUB_LOGIN=${login}"]) {
    status = sh(
      script: 'curl -s -o /dev/null -w "%{http_code}" -H "Authorization: token $GITHUB_CREDENTIAL_PSW" ' +
        '"https://api.github.com/orgs/linagora/members/$GITHUB_LOGIN"',
      returnStdout: true
    ).trim()
  }
  echo "GitHub org membership check returned HTTP ${status} for '${login}'"
  if (status == '204') {
    return true
  }
  if (status == '404') {
    return false
  }
  error("GitHub API error ${status} while checking the membership of '${login}'")
}

def githubApi(String path) {
  def json
  withEnv(["GITHUB_API_PATH=${path}", "GITHUB_API_REPOSITORY=${githubRepository()}"]) {
    json = sh(
      script: 'curl -sf -H "Authorization: token $GITHUB_CREDENTIAL_PSW" ' +
        '"https://api.github.com/repos/$GITHUB_API_REPOSITORY/$GITHUB_API_PATH"',
      returnStdout: true
    )
  }
  return readJSON(text: json)
}

// Binds the GitHub token as GITHUB_CREDENTIAL_USR / GITHUB_CREDENTIAL_PSW around the given steps only
def withGithubCredential(Closure body) {
  withCredentials([usernamePassword(credentialsId: 'github',
      usernameVariable: 'GITHUB_CREDENTIAL_USR', passwordVariable: 'GITHUB_CREDENTIAL_PSW')]) {
    body()
  }
}

// Logs in Docker Hub in a throwaway Docker config deleted afterwards, so that the credential never
// stays on the agent where a later build could read it
def withDockerHubLogin(Closure body) {
  withEnv(["DOCKER_CONFIG=${pwd(tmp: true)}/docker-config"]) {
    try {
      withCredentials([usernamePassword(credentialsId: 'dockerHub',
          usernameVariable: 'DOCKER_HUB_CREDENTIAL_USR', passwordVariable: 'DOCKER_HUB_CREDENTIAL_PSW')]) {
        sh 'mkdir -p "$DOCKER_CONFIG" && printf \'%s\' "$DOCKER_HUB_CREDENTIAL_PSW" | docker login -u "$DOCKER_HUB_CREDENTIAL_USR" --password-stdin'
      }
      body()
    } finally {
      sh 'docker logout || true; rm -rf "$DOCKER_CONFIG"'
    }
  }
}

pipeline {
    agent any

    environment {
        DOCKER_HUB_CREDENTIAL = credentials('dockerHub')
        GITHUB_CREDENTIAL = credentials('github')
    }

    options {
        timeout(time: 1, unit: 'HOURS')
        disableConcurrentBuilds()
    }

    stages {
      stage('Build Test Images') {
          steps {
              sh 'bash run_test.sh --skip-java --skip-php'
          }
      }
      stage('Compile and Test') {
          steps {
              sh 'bash run_test.sh --skip-java --skip-build'
          }
       }
       stage('Run Integration Test') {
          steps {
              sh 'bash run_test.sh --skip-php --skip-build'
          }
      }
      stage('Deliver Docker images for PR') {
        when {
          changeRequest()
        }
        steps {
          script {
            if (env.CHANGE_FORK) {
              def forkOwner = env.CHANGE_FORK.split('/')[0]
              def memberStatus = sh(
                script: """curl -s -o /dev/null -w "%{http_code}" \
                  -H "Authorization: token \${GITHUB_CREDENTIAL_PSW}" \
                  "https://api.github.com/orgs/linagora/members/${forkOwner}" """,
                returnStdout: true
              ).trim()
              echo "GitHub org membership check returned HTTP ${memberStatus} for '${forkOwner}'"
              if (memberStatus == '204') {
                echo "Fork owner '${forkOwner}' is a linagora org member, proceeding."
              } else if (memberStatus == '404') {
                echo "Fork owner '${forkOwner}' is not a member of the linagora organization."
                def approvedByMember = false
                def commentsJson = sh(
                  script: """curl -s \
                    -H "Authorization: token \${GITHUB_CREDENTIAL_PSW}" \
                    "https://api.github.com/repos/linagora/esn-sabre/issues/\${CHANGE_ID}/comments" """,
                  returnStdout: true
                ).trim()
                def comments = new groovy.json.JsonSlurper().parseText(commentsJson)
                for (comment in comments) {
                  if (comment.body.trim().toLowerCase() == 'build this please') {
                    def commenter = comment.user.login
                    def commenterStatus = sh(
                      script: """curl -s -o /dev/null -w "%{http_code}" \
                        -H "Authorization: token \${GITHUB_CREDENTIAL_PSW}" \
                        "https://api.github.com/orgs/linagora/members/${commenter}" """,
                      returnStdout: true
                    ).trim()
                    if (commenterStatus == '204') {
                      echo "Build approved by linagora member '${commenter}', proceeding."
                      approvedByMember = true
                      break
                    }
                  }
                }
                if (!approvedByMember) {
                  echo "No linagora member approval found. Skipping PR image delivery."
                  return
                }
              } else if (memberStatus == '401' || memberStatus == '403') {
                error("Authentication/permission error validating fork owner: ${memberStatus}")
              } else {
                error("GitHub API error ${memberStatus} while checking membership for '${forkOwner}'")
              }
            }

            sh 'docker build -t linagora/esn-sabre-pr:$CHANGE_ID .'
            sh 'docker login -u $DOCKER_HUB_CREDENTIAL_USR -p $DOCKER_HUB_CREDENTIAL_PSW'
            sh 'docker push linagora/esn-sabre-pr:$CHANGE_ID'
            sh """
              HTTP_STATUS=\$(curl -s -o /tmp/gh_comment_response.json -w "%{http_code}" -X POST \\
                -H "Authorization: token \${GITHUB_CREDENTIAL_PSW}" \\
                -H "Content-Type: application/json" \\
                -d "{\\"body\\": \\"Docker image published for this PR: linagora/esn-sabre-pr:\${CHANGE_ID}\\"}" \\
                "https://api.github.com/repos/linagora/esn-sabre/issues/\${CHANGE_ID}/comments")
              if [ "\$HTTP_STATUS" -lt 200 ] || [ "\$HTTP_STATUS" -ge 300 ]; then
                echo "WARNING: GitHub API comment failed with HTTP \$HTTP_STATUS"
                cat /tmp/gh_comment_response.json
              fi
            """
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
                sh 'docker build -t linagora/esn-sabre:$DOCKER_TAG .'
                sh 'docker login -u $DOCKER_HUB_CREDENTIAL_USR -p $DOCKER_HUB_CREDENTIAL_PSW'
                sh 'docker push linagora/esn-sabre:$DOCKER_TAG'
          }
        }
      }
    }

    post {
        always {
            deleteDir() /* clean up our workspace */
        }
    }
}

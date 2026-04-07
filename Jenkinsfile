pipeline {
    agent any

    environment {
        DOCKER_HUB_CREDENTIAL = credentials('dockerHub')
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

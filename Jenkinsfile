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
        stage('Compile and Test') {
            steps {
                sh 'bash run_test.sh'
            }
        }
    }
    post {
        always {
            deleteDir() /* clean up our workspace */
        }
    }
}

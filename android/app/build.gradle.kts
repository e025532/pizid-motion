plugins {
    id("com.android.application")
}

android {
    namespace = "org.pizid.healthconnectsync"
    compileSdk = 37

    defaultConfig {
        applicationId = "org.pizid.healthconnectsync"
        minSdk = 37
        targetSdk = 37
        versionCode = 9
        versionName = "0.4.3"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        buildConfigField("String", "API_BASE_URL", "\"https://health.home.pizid.org/api/v1\"")
    }

    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.19.0")
    implementation("androidx.activity:activity-ktx:1.13.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.11.0")
    implementation("androidx.work:work-runtime-ktx:2.11.2")
}

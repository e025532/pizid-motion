plugins {
    id("com.android.application")
}

val apiBaseUrl = providers.gradleProperty("PIZID_API_BASE_URL")
    .orElse(providers.environmentVariable("PIZID_API_BASE_URL"))
    .orElse("https://health.example.org/api/v1")
    .get()

android {
    namespace = "org.pizid.healthconnectsync"
    compileSdk = 37

    defaultConfig {
        applicationId = "org.pizid.healthconnectsync"
        minSdk = 37
        targetSdk = 37
        versionCode = 10
        versionName = "0.4.4"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        val escapedApiBaseUrl = apiBaseUrl.replace("\\", "\\\\").replace("\"", "\\\"")
        buildConfigField("String", "API_BASE_URL", "\"$escapedApiBaseUrl\"")
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

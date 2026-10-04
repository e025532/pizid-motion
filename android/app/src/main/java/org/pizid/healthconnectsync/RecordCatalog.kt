package org.pizid.healthconnectsync

import android.health.connect.HealthPermissions
import android.health.connect.datatypes.*

data class RecordSpec(
    val type: String,
    val recordClass: Class<out Record>,
    val permission: String
)

object RecordCatalog {
    val all: List<RecordSpec> = listOf(
        spec("active_calories_burned", ActiveCaloriesBurnedRecord::class.java, HealthPermissions.READ_ACTIVE_CALORIES_BURNED),
        spec("activity_intensity", ActivityIntensityRecord::class.java, HealthPermissions.READ_ACTIVITY_INTENSITY),
        spec("alcohol_consumption", AlcoholConsumptionRecord::class.java, HealthPermissions.READ_ALCOHOL_CONSUMPTION),
        spec("basal_body_temperature", BasalBodyTemperatureRecord::class.java, HealthPermissions.READ_BASAL_BODY_TEMPERATURE),
        spec("basal_metabolic_rate", BasalMetabolicRateRecord::class.java, HealthPermissions.READ_BASAL_METABOLIC_RATE),
        spec("blood_glucose", BloodGlucoseRecord::class.java, HealthPermissions.READ_BLOOD_GLUCOSE),
        spec("blood_pressure", BloodPressureRecord::class.java, HealthPermissions.READ_BLOOD_PRESSURE),
        spec("body_fat", BodyFatRecord::class.java, HealthPermissions.READ_BODY_FAT),
        spec("body_temperature", BodyTemperatureRecord::class.java, HealthPermissions.READ_BODY_TEMPERATURE),
        spec("body_water_mass", BodyWaterMassRecord::class.java, HealthPermissions.READ_BODY_WATER_MASS),
        spec("bone_mass", BoneMassRecord::class.java, HealthPermissions.READ_BONE_MASS),
        spec("cervical_mucus", CervicalMucusRecord::class.java, HealthPermissions.READ_CERVICAL_MUCUS),
        spec("cycling_pedaling_cadence", CyclingPedalingCadenceRecord::class.java, HealthPermissions.READ_EXERCISE),
        spec("distance", DistanceRecord::class.java, HealthPermissions.READ_DISTANCE),
        spec("elevation_gained", ElevationGainedRecord::class.java, HealthPermissions.READ_ELEVATION_GAINED),
        spec("exercise_session", ExerciseSessionRecord::class.java, HealthPermissions.READ_EXERCISE),
        spec("floors_climbed", FloorsClimbedRecord::class.java, HealthPermissions.READ_FLOORS_CLIMBED),
        spec("heart_rate", HeartRateRecord::class.java, HealthPermissions.READ_HEART_RATE),
        spec("heart_rate_variability_rmssd", HeartRateVariabilityRmssdRecord::class.java, HealthPermissions.READ_HEART_RATE_VARIABILITY),
        spec("height", HeightRecord::class.java, HealthPermissions.READ_HEIGHT),
        spec("hydration", HydrationRecord::class.java, HealthPermissions.READ_HYDRATION),
        spec("intermenstrual_bleeding", IntermenstrualBleedingRecord::class.java, HealthPermissions.READ_INTERMENSTRUAL_BLEEDING),
        spec("lean_body_mass", LeanBodyMassRecord::class.java, HealthPermissions.READ_LEAN_BODY_MASS),
        spec("menstrual_cycle_phase", MenstrualCyclePhaseRecord::class.java, HealthPermissions.READ_MENSTRUAL_CYCLE_PHASE),
        spec("menstruation_flow", MenstruationFlowRecord::class.java, HealthPermissions.READ_MENSTRUATION),
        spec("menstruation_period", MenstruationPeriodRecord::class.java, HealthPermissions.READ_MENSTRUATION),
        spec("mindfulness_session", MindfulnessSessionRecord::class.java, HealthPermissions.READ_MINDFULNESS),
        spec("nutrition", NutritionRecord::class.java, HealthPermissions.READ_NUTRITION),
        spec("ovulation_test", OvulationTestRecord::class.java, HealthPermissions.READ_OVULATION_TEST),
        spec("oxygen_saturation", OxygenSaturationRecord::class.java, HealthPermissions.READ_OXYGEN_SATURATION),
        spec("planned_exercise_session", PlannedExerciseSessionRecord::class.java, HealthPermissions.READ_PLANNED_EXERCISE),
        spec("power", PowerRecord::class.java, HealthPermissions.READ_POWER),
        spec("respiratory_rate", RespiratoryRateRecord::class.java, HealthPermissions.READ_RESPIRATORY_RATE),
        spec("resting_heart_rate", RestingHeartRateRecord::class.java, HealthPermissions.READ_RESTING_HEART_RATE),
        spec("sexual_activity", SexualActivityRecord::class.java, HealthPermissions.READ_SEXUAL_ACTIVITY),
        spec("skin_temperature", SkinTemperatureRecord::class.java, HealthPermissions.READ_SKIN_TEMPERATURE),
        spec("sleep_session", SleepSessionRecord::class.java, HealthPermissions.READ_SLEEP),
        spec("speed", SpeedRecord::class.java, HealthPermissions.READ_SPEED),
        spec("steps_cadence", StepsCadenceRecord::class.java, HealthPermissions.READ_STEPS),
        spec("steps", StepsRecord::class.java, HealthPermissions.READ_STEPS),
        spec("symptom", SymptomRecord::class.java, PermissionCatalog.symptomReadPermissions.first()),
        spec("total_calories_burned", TotalCaloriesBurnedRecord::class.java, HealthPermissions.READ_TOTAL_CALORIES_BURNED),
        spec("vo2_max", Vo2MaxRecord::class.java, HealthPermissions.READ_VO2_MAX),
        spec("weight", WeightRecord::class.java, HealthPermissions.READ_WEIGHT),
        spec("wheelchair_pushes", WheelchairPushesRecord::class.java, HealthPermissions.READ_WHEELCHAIR_PUSHES)
    )

    private fun spec(type: String, recordClass: Class<out Record>, permission: String) =
        RecordSpec(type, recordClass, permission)
}
